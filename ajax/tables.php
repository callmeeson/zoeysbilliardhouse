<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

function start_billiard_session(array $in, int $fromReservationId = 0): array {
    $tableId   = (int)($in['table_id'] ?? 0);
    $customerId = (int)($in['customer_id'] ?? 0);
    $walkInName = trim((string)($in['walkin_name'] ?? ''));
    $hours     = (float)($in['hours'] ?? 0);
    $promo     = (int)($in['promo'] ?? 0) === 1;
    $payment   = (float)($in['payment'] ?? 0);
    $freeHour  = (int)($in['free_hour'] ?? 0) === 1;

    $t = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
    if (!$t) throw new RuntimeException('Table not found.');
    if ($t['status'] === 'maintenance') throw new RuntimeException('Table is under maintenance.');
    if ($t['status'] === 'occupied') throw new RuntimeException('Table is already occupied.');

    // Walk-in vs reservation conflict: block when a confirmed reservation
    // window overlaps the intended play window (now -> end).
    $playSecs = (int)round(($hours > 0 ? $hours : 1) * 3600);
    $resEnd = date('Y-m-d H:i:s', time() + max(1800, $playSecs));
    $resNow = now();
    $reserved = db_row("
        SELECT customer_name, start_time, end_time FROM reservations
        WHERE table_id = ? AND reservation_date = CURDATE()
          AND status = 'confirmed'
          AND start_time < ? AND end_time > ?
          " . ($fromReservationId > 0 ? "AND id <> ?" : "") . "
        ORDER BY start_time LIMIT 1
    ", $fromReservationId > 0 ? [$tableId, $resEnd, $resNow, $fromReservationId] : [$tableId, $resEnd, $resNow]);
    if ($reserved) {
        throw new RuntimeException('Table is reserved for ' . $reserved['customer_name'] . ' from ' . substr($reserved['start_time'], 0, 5) . '.');
    }

    $customer = null;
    $customerName = null;
    if ($customerId > 0) {
        $customer = db_row('SELECT * FROM customers WHERE id = ? AND is_active = 1', [$customerId]);
        if (!$customer) throw new RuntimeException('Customer not found.');
        $activeSessions = db_value("SELECT COUNT(*) FROM billiard_sessions WHERE customer_id = ? AND status = 'open'", [$customerId]);
        if ((int)$activeSessions > 0) throw new RuntimeException("{$customer['name']} already has an active session on another table.");
        $customerName = $customer['name'];
    } elseif ($walkInName !== '') {
        $customerName = $walkInName;
    }

    $rate = (float)$t['rate_per_hour'];

    if ($hours <= 0) {
        // legacy instant start (no upfront payment)
        $startTime = now();
        $endTime   = date('Y-m-d H:i:s', strtotime($startTime) + 3600); // default 1 hour
        db()->prepare("INSERT INTO billiard_sessions (table_id, customer_id, customer_name, start_time, end_time, status, user_id)
                       VALUES (?,?,?,?,?, 'open', ?)")
             ->execute([$tableId, $customerId > 0 ? $customerId : null, $customerName, $startTime, $endTime, (int)$_SESSION['user_id']]);
        db()->prepare("UPDATE tables SET status = 'occupied' WHERE id = ?")->execute([$tableId]);
        return ['session_id' => (int)db()->lastInsertId(), 'change' => 0.0, 'free_hour_applied' => false];
    }
    if ($hours < 0.5 || $hours > 5) throw new RuntimeException('Select between 30 min and 5 hours.');

    $freeHourApplied = false;
    if ($freeHour) {
        if (!$customerId) throw new RuntimeException('Free hour claim requires a registered customer.');
        $loyalty = db_value('SELECT loyalty_stamps FROM customers WHERE id = ? FOR UPDATE', [$customerId]);
        if ((int)$loyalty < 10) throw new RuntimeException('Not enough stamps — 10 stamps required to claim a free hour.');
        if ($hours < 1) throw new RuntimeException('Claiming a free hour requires at least 1 hour.');
        $freeHourApplied = true;
    }
    $amount   = $hours * $rate;
    if ($freeHourApplied) $amount -= $rate; // one hour free
    $promoDiscount = 0.0;
    if ($promo) {
        $p = active_promo();
        if ($p) $promoDiscount = round($amount * (float)$p['discount_percent'] / 100, 2);
    }
    $freeDiscount = $freeHourApplied ? (float)$rate : 0.0;
    // Subtotal carries the full time at rate (free hour included), discount = promo + loyalty.
    $subtotal = $amount + $freeDiscount;
    $discount = $promoDiscount + $freeDiscount;
    $total    = $subtotal - $discount;
    if ($payment < $total - 0.001) throw new RuntimeException('Payment is less than the total.');

    $db = db();
    $db->beginTransaction();
    try {
        $startTime = now();
        $endTime   = date('Y-m-d H:i:s', strtotime($startTime) + (int)round($hours * 3600));

        $db->prepare("INSERT INTO billiard_sessions
                      (table_id, customer_id, customer_name, start_time, end_time, extended_hours, prepaid, free_hour_used, status, user_id)
                      VALUES (?,?,?,?,?,?,?,?, 'open', ?)")
              ->execute([$tableId, $customerId > 0 ? $customerId : null, $customerName, $startTime, $endTime, $hours, $total, $freeHourApplied ? 1 : 0, (int)$_SESSION['user_id']]);
        $sid = (int)$db->lastInsertId();

        $reference = make_reference();
        for ($i = 0; $i < 5; $i++) {
            if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
            $reference = make_reference();
        }
        $db->prepare("
            INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method,
                               billiard_session_id, billiard_hours, billiard_amount)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $reference, (int)$_SESSION['user_id'], $subtotal, $discount, $total, 'cash',
            $sid, $hours, $total,
        ]);

        if ($freeHourApplied) {
            $db->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps - 10, loyalty_completed = loyalty_completed + 1 WHERE id = ?')->execute([$customerId]);
        }
        $db->prepare("UPDATE tables SET status = 'occupied' WHERE id = ?")->execute([$tableId]);
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    return ['session_id' => $sid, 'change' => $payment - $total, 'free_hour_applied' => $freeHourApplied];
}

switch ($action) {

    case 'list': {
        $tables = db_all("SELECT * FROM tables ORDER BY FIELD(type, 'regular', 'vip', 'ktv'), table_number");
        $sessions = [];
        $stampMap = [];
        $nextRes = [];
        foreach (db_all("SELECT table_id, customer_name, start_time FROM reservations
                         WHERE reservation_date = CURDATE() AND status IN ('confirmed','playing')
                         ORDER BY start_time") as $rr) {
            if (!isset($nextRes[(int)$rr['table_id']])) $nextRes[(int)$rr['table_id']] = $rr;
        }
        foreach (db_all("SELECT id, loyalty_stamps, loyalty_completed FROM customers") as $c) {
            $stampMap[(int)$c['id']] = [(int)$c['loyalty_stamps'], (int)$c['loyalty_completed']];
        }
        foreach (db_all("SELECT * FROM billiard_sessions WHERE status = 'open'") as $s) {
            $s['promo_applied'] = (float)db_value('SELECT COALESCE(SUM(discount),0) FROM sales WHERE billiard_session_id = ?', [$s['id']]) > 0;
            $stamps = !empty($s['customer_id']) && isset($stampMap[(int)$s['customer_id']]) ? $stampMap[(int)$s['customer_id']] : [0, 0];
            $s['customer_stamps'] = $stamps[0];
            $s['customer_completed'] = $stamps[1];
            $sessions[$s['table_id']] = $s;
        }
        foreach ($tables as &$t) {
            $t['session'] = $sessions[$t['id']] ?? null;
            if ($t['session']) {
                $t['session']['elapsed_minutes'] = max(0, (int)floor((time() - strtotime($t['session']['start_time'])) / 60));
            }
            $t['next_reservation'] = $nextRes[$t['id']] ?? null;
        }
        unset($t);
        json_response(200, ['ok' => true, 'tables' => $tables]);
    }
    break;

    case 'start_session': {
        try {
            $r = start_billiard_session($_POST);
            json_response(200, ['ok' => true, 'message' => 'Billiard session started.'] + $r);
        } catch (Throwable $ex) {
            json_response(422, ['ok' => false, 'message' => $ex->getMessage()]);
        }
    }
    break;

    case 'start_from_reservation': {
        $rid = (int)($_POST['reservation_id'] ?? 0);
        $res = db_row("
            SELECT r.*, t.rate_per_hour, t.status AS table_status
            FROM reservations r JOIN tables t ON t.id = r.table_id
            WHERE r.id = ? AND r.status IN ('playing','confirmed')
        ", [$rid]);
        if (!$res) json_response(404, ['ok' => false, 'message' => 'Reservation not found or already started.']);

        // Duration from the booked window (end may exceed 24:00 for late-night bookings)
        [$sh, $sm] = array_map('intval', explode(':', $res['start_time']));
        [$eh, $em] = array_map('intval', explode(':', $res['end_time']));
        $durMin = ($eh * 60 + $em) - ($sh * 60 + $sm);

        // Late-arrival grace: the first 15 minutes past the booked start are free;
        // the excess beyond the grace is consumed from the customer's booked time.
        $nowMin = ((int)date('G')) * 60 + (int)date('i');
        $lateMin = $nowMin - ($sh * 60 + $sm);
        $consumed = max(0, $lateMin - 15);
        if ($consumed >= $durMin) {
            json_response(422, ['ok' => false, 'message' => 'The reserved window has fully elapsed (arrived ' . $lateMin . ' min late).']);
        }
        $durMin = max(30, $durMin - $consumed);

        $hours = max(0.5, min(5.0, round($durMin / 60, 2)));
        $dp = round((float)$res['downpayment'], 2);
        $paymentNow = round((float)($_POST['payment'] ?? 0), 2);
        try {
            $r = start_billiard_session([
                'table_id'   => (int)$res['table_id'],
                'customer_id' => (int)$res['customer_id'],
                'walkin_name' => (int)$res['is_walkin'] === 1 ? (string)$res['customer_name'] : '',
                'hours'      => $hours,
                'promo'      => (int)($_POST['promo'] ?? 0),
                'free_hour'  => 0,
                'payment'    => $paymentNow + $dp,
            ], (int)$res['id']);
                        // Sync the reservation window to the actual session times (early/late start shifts both).
            $startDt = now();
            $endDt   = date('Y-m-d H:i:s', strtotime($startDt) + (int)round($hours * 3600));
            $newStartTime = substr($startDt, 11, 5);
            $newEndTime = substr($endDt, 11, 5);
            if (substr($endDt, 0, 10) !== substr($startDt, 0, 10)) {
                [$eh, $em] = array_map('intval', explode(':', $newEndTime));
                $newEndTime = sprintf('%02d:%02d', $eh + 24, $em);
            }
            db()->prepare("UPDATE reservations SET status = 'playing', session_id = ?, start_time = ?, end_time = ? WHERE id = ?")
                ->execute([(int)$r['session_id'], $newStartTime, $newEndTime, $rid]);
            json_response(200, ['ok' => true, 'message' => 'Session started for the reservation.'] + $r);
        } catch (Throwable $ex) {
            json_response(422, ['ok' => false, 'message' => $ex->getMessage()]);
        }
    }
    break;

    case 'apply_free_hour': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $s = db_row('SELECT * FROM billiard_sessions WHERE id = ? AND status = "open"', [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or already closed.']);
        if (empty($s['customer_id'])) json_response(422, ['ok' => false, 'message' => 'Free hour claim requires a registered customer.']);
        if ((int)$s['free_hour_used'] === 1) json_response(422, ['ok' => false, 'message' => 'Free hour already claimed for this session.']);
        $stamps = (int)db_value('SELECT loyalty_stamps FROM customers WHERE id = ?', [(int)$s['customer_id']]);
        if ($stamps < 10) json_response(422, ['ok' => false, 'message' => 'Not enough stamps — 10 stamps required to claim a free hour.']);
        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE billiard_sessions SET free_hour_used = 1, extended_hours = extended_hours + 1, end_time = DATE_ADD(end_time, INTERVAL 1 HOUR) WHERE id = ?")->execute([$sid]);
            $db->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps - 10, loyalty_completed = loyalty_completed + 1 WHERE id = ?')->execute([(int)$s['customer_id']]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to apply free hour.']);
        }
        $stampsNow = (int)db_value('SELECT loyalty_stamps FROM customers WHERE id = ?', [(int)$s['customer_id']]);
        json_response(200, ['ok' => true, 'message' => 'Free hour applied — 1 hour added to the session at no charge.', 'stamps' => $stampsNow]);
    }
    break;

    case 'extend_session': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $hours = (float)($_POST['hours'] ?? 0);
        $payment = (float)($_POST['payment'] ?? 0);
        $promo = (int)($_POST['promo'] ?? 0) === 1;
        $s = db_row('SELECT * FROM billiard_sessions WHERE id = ? AND status = "open"', [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or already closed.']);
        if ($hours < 0.5 || $hours > 48) json_response(422, ['ok' => false, 'message' => 'Extend between 0.5 and 48 hours.']);

        // Block extension when the added time runs into a confirmed reservation.
        $curEndSec = strtotime($s['end_time']);
        $curEndT = date('H:i:s', $curEndSec);
        $newEndT = date('H:i:s', $curEndSec + (int)round($hours * 3600));
        $reserved = db_row("
            SELECT customer_name, start_time FROM reservations
            WHERE table_id = ? AND reservation_date = CURDATE()
              AND status = 'confirmed'
              AND start_time < ? AND end_time > ? AND end_time > CURTIME()
            ORDER BY start_time LIMIT 1
        ", [$s['table_id'], $newEndT, $curEndT]);
        if ($reserved) {
            json_response(422, ['ok' => false, 'message' => 'Cannot extend - table is reserved for ' . $reserved['customer_name'] . ' from ' . substr($reserved['start_time'], 0, 5) . '.']);
        }

        $txt = rtrim(rtrim(sprintf('%.2f', $hours), '0'), '.');

        // Prepaid sessions: extending requires paying the additional hours up front.
        if ((float)$s['prepaid'] > 0) {
            $t = db_row('SELECT * FROM tables WHERE id = ?', [$s['table_id']]);
            $rate   = (float)$t['rate_per_hour'];
            $amount = $hours * $rate;
            $discount = 0.0;
            if ($promo) {
                $p = active_promo();
                if ($p) $discount = round($amount * (float)$p['discount_percent'] / 100, 2);
            }
            $total  = $amount - $discount;
            if ($payment < $total - 0.001) json_response(422, ['ok' => false, 'message' => 'Payment is less than the additional amount.']);

            $db = db();
            $db->beginTransaction();
            try {
                $minutes = (int)round($hours * 60);
                $db->prepare('UPDATE billiard_sessions SET extended_hours = extended_hours + ?, prepaid = prepaid + ?, end_time = DATE_ADD(end_time, INTERVAL ? MINUTE) WHERE id = ?')
                    ->execute([$hours, $total, $minutes, $sid]);
                $reference = make_reference();
                for ($i = 0; $i < 5; $i++) {
                    if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
                    $reference = make_reference();
                }
                $db->prepare("
                    INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method,
                                       billiard_session_id, billiard_hours, billiard_amount)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $reference, (int)$_SESSION['user_id'], $amount, $discount, $total, 'cash',
                    $sid, $hours, $total,
                ]);
                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                json_response(500, ['ok' => false, 'message' => 'Failed to extend session.']);
            }
            $newExt = (float)$s['extended_hours'] + $hours;
            json_response(200, ['ok' => true, 'message' => 'Extended by ' . $txt . ' hr(s). Charged ' . money($total) . '.',
                'hours' => $hours, 'amount' => $total, 'extended_hours' => $newExt,
                'prepaid' => (float)$s['prepaid'] + $total, 'change' => $payment - $total]);
        }

        // Legacy (unpaid) sessions: extension just adds committed time, billed at end.
        $minutes = (int)round($hours * 60);
        db()->prepare('UPDATE billiard_sessions SET extended_hours = extended_hours + ?, end_time = DATE_ADD(end_time, INTERVAL ? MINUTE) WHERE id = ?')->execute([$hours, $minutes, $sid]);
        json_response(200, ['ok' => true, 'message' => 'Extended by ' . $txt . ' hr(s).']);
    }
    break;

    case 'end_session': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $s = db_row("
            SELECT bs.*, t.table_number, t.rate_per_hour
            FROM billiard_sessions bs JOIN tables t ON t.id = bs.table_id
            WHERE bs.id = ? AND bs.status = 'open'
        ", [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or already closed.']);

        // Use the pre-calculated end_time (includes extensions)
        $scheduledEnd = strtotime($s['end_time']);
        $actualEnd = time();
        $endTime = max($scheduledEnd, $actualEnd); // use later of scheduled vs actual
        $minutes = max(1, (int)ceil(($endTime - strtotime($s['start_time'])) / 60));
        $hours   = max(1, (int)ceil($minutes / 60), (int)round((float)$s['extended_hours']));

        // Prepaid sessions were already paid at start / on extend — just close, no new sale.
        if ((float)$s['prepaid'] > 0) {
            $amount = (float)$s['prepaid'];
            $promoDiscount = (float)db_value('SELECT COALESCE(SUM(discount),0) FROM sales WHERE billiard_session_id = ?', [$sid]);
            $freeDiscount = ((int)$s['free_hour_used'] === 1) ? (float)$s['rate_per_hour'] : 0.0;
            $subtotal = $amount + $promoDiscount + $freeDiscount;
            $discount = $promoDiscount + $freeDiscount;
            $dp = (float)db_value('SELECT downpayment FROM reservations WHERE session_id = ?', [$sid]);
            $reference = db_value('SELECT reference FROM sales WHERE billiard_session_id = ? LIMIT 1', [$sid]) ?: make_reference();
            db()->prepare("UPDATE billiard_sessions SET end_time = FROM_UNIXTIME(?), hours = ?, amount = ?, status = 'closed', user_id = ?
                          WHERE id = ?")
                ->execute([$endTime, $hours, $amount, (int)$_SESSION['user_id'], $sid]);
            db()->prepare("UPDATE tables SET status = 'available' WHERE id = ?")->execute([$s['table_id']]);
            db()->prepare("UPDATE reservations SET status = 'completed' WHERE session_id = ? AND status = 'playing'")->execute([$sid]);
            $award = award_loyalty_stamp($s, $hours);
            json_response(200, ['ok' => true, 'message' => 'Game ended. Paid ' . money($amount) . '.', 'session' => [
                'reference'    => $reference,
                'table_number' => $s['table_number'],
                'hours'        => $hours,
                'rate'         => (float)$s['rate_per_hour'],
                'subtotal'     => $subtotal,
                'discount'     => $discount,
                'free_hour'    => $freeDiscount,
                'promo_discount' => $promoDiscount,
                'promo_applied'  => $promoDiscount > 0,
                'amount'       => $amount,
                'downpayment'  => round($dp, 2),
                'paid_at_start' => round(max(0.0, $amount - $dp), 2),
                'stamp_awarded' => $award['awarded'],
                'stamps_now'   => $award['stamps_now'],
            ]]);
        }

        // Legacy unpaid session: charge the time now.
        $freeDiscount = ((int)$s['free_hour_used'] === 1) ? (float)$s['rate_per_hour'] : 0.0;
        $subtotal = $hours * (float)$s['rate_per_hour'];
        $amount  = max(0.0, $subtotal - $freeDiscount);
        $discount = $freeDiscount;

        $reference = make_reference();
        for ($i = 0; $i < 5; $i++) {
            if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
            $reference = make_reference();
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method,
                                   billiard_session_id, billiard_hours, billiard_amount)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $reference, (int)$_SESSION['user_id'], $subtotal, $discount, $amount, 'cash',
                $sid, $hours, $amount,
            ]);
            $db->prepare("UPDATE billiard_sessions SET end_time = FROM_UNIXTIME(?), hours = ?, amount = ?, status = 'closed', user_id = ?
                          WHERE id = ?")
                ->execute([$endTime, $hours, $amount, (int)$_SESSION['user_id'], $sid]);
            $db->prepare("UPDATE tables SET status = 'available' WHERE id = ?")->execute([$s['table_id']]);
            $db->prepare("UPDATE reservations SET status = 'completed' WHERE session_id = ? AND status = 'playing'")->execute([$sid]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to end session: ' . $ex->getMessage()]);
        }
        $award = award_loyalty_stamp($s, $hours);
        json_response(200, ['ok' => true, 'message' => 'Game ended. Charged ' . money($amount) . '.', 'session' => [
            'reference'    => $reference,
            'table_number' => $s['table_number'],
            'hours'        => $hours,
            'rate'         => (float)$s['rate_per_hour'],
            'subtotal'     => $subtotal,
            'discount'     => $discount,
            'free_hour'    => $freeDiscount,
            'promo_discount' => 0.0,
            'promo_applied'  => false,
            'amount'       => $amount,
            'downpayment'  => 0.0,
            'paid_at_start' => 0.0,
            'stamp_awarded' => $award['awarded'],
            'stamps_now'   => $award['stamps_now'],
        ]]);
    }
    break;

    case 'cancel_session': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $voidReason = trim($_POST['void_reason'] ?? '');
        $s = db_row('SELECT * FROM billiard_sessions WHERE id = ? AND status = "open"', [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or already closed.']);
        db()->prepare("UPDATE billiard_sessions SET status = 'void', void_reason = ? WHERE id = ?")->execute([$voidReason ?: null, $sid]);
        db()->prepare("UPDATE tables SET status = 'available' WHERE id = ?")->execute([$s['table_id']]);
        db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE session_id = ? AND status = 'playing'")->execute([$sid]);
        json_response(200, ['ok' => true, 'message' => 'Session voided.']);
    }
    break;

    case 'set_maintenance': {
        $tableId = (int)($_POST['table_id'] ?? 0);
        $t = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$t) json_response(404, ['ok' => false, 'message' => 'Table not found.']);
        if ($t['status'] === 'occupied') json_response(422, ['ok' => false, 'message' => 'Cannot toggle a table in use.']);
        $new = $t['status'] === 'maintenance' ? 'available' : 'maintenance';
        db()->prepare('UPDATE tables SET status = ? WHERE id = ?')->execute([$new, $tableId]);
        audit_log('table_maintenance', "Table '{$t['table_number']}' (#{$tableId}) set to {$new}");
        json_response(200, ['ok' => true, 'message' => 'Table updated to ' . $new . '.']);
    }
    break;

    case 'set_status': {
        if (!is_admin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Admins only.']);
        $tableId = (int)($_POST['table_id'] ?? 0);
        $status  = $_POST['status'] ?? '';
        if (!in_array($status, ['available', 'occupied', 'maintenance'], true)) {
            json_response(422, ['ok' => false, 'message' => 'Invalid status.']);
        }
        $t = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$t) json_response(404, ['ok' => false, 'message' => 'Table not found.']);

        if ($status === 'occupied') {
            $open = db_row('SELECT id FROM billiard_sessions WHERE table_id = ? AND status = "open"', [$tableId]);
            if (!$open) {
                db()->prepare("INSERT INTO billiard_sessions (table_id, start_time, status, user_id) VALUES (?,?,?,?)")
                    ->execute([$tableId, now(), 'open', (int)$_SESSION['user_id']]);
            }
        } else {
            db()->prepare("UPDATE billiard_sessions SET status = 'void' WHERE table_id = ? AND status = 'open'")
                ->execute([$tableId]);
        }
        db()->prepare('UPDATE tables SET status = ? WHERE id = ?')->execute([$status, $tableId]);
        json_response(200, ['ok' => true, 'message' => 'Table marked ' . $status . '.']);
    }
    break;

    case 'save': {
        if (!is_admin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Admins only.']);
        $tableId = (int)($_POST['id'] ?? 0);
        $number = trim($_POST['table_number'] ?? '');
        $type   = $_POST['type'] ?? 'regular';
        $rate   = (float)($_POST['rate_per_hour'] ?? 0);
        if ($number === '' || $rate <= 0) json_response(422, ['ok' => false, 'message' => 'Table number and rate required.']);
        if (!in_array($type, ['regular', 'vip', 'ktv', 'kubo'], true)) json_response(422, ['ok' => false, 'message' => 'Invalid table type.']);

        if ($tableId > 0) {
            db()->prepare('UPDATE tables SET table_number = ?, type = ?, rate_per_hour = ? WHERE id = ?')
                ->execute([$number, $type, $rate, $tableId]);
        } else {
            db()->prepare('INSERT INTO tables (table_number, type, rate_per_hour) VALUES (?,?,?)')
                ->execute([$number, $type, $rate]);
        }
        json_response(200, ['ok' => true, 'message' => 'Table saved.']);
    }
    break;

    case 'delete': {
        if (!is_admin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Admins only.']);
        $tableId = (int)($_POST['id'] ?? 0);
        $t = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$t) json_response(404, ['ok' => false, 'message' => 'Table not found.']);
        if ($t['status'] !== 'available') json_response(422, ['ok' => false, 'message' => 'Only available tables can be deleted.']);
        db()->prepare('DELETE FROM tables WHERE id = ?')->execute([$tableId]);
        json_response(200, ['ok' => true, 'message' => 'Table deleted.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}