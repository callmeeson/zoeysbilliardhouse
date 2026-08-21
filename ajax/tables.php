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
    $karaoke = (int)($in['karaoke'] ?? 0) === 1 && $t['type'] === 'vip';

    // Walk-in vs reservation conflict: block when a confirmed reservation
    // window overlaps the intended play window (now -> end). Windows may
    // cross midnight, so the check also covers yesterday's overflow bookings
    // (end_time > 24:00) and tomorrow's reservations when the play window
    // itself runs past midnight. All comparisons are minute-of-day based so
    // >24:00 values compare correctly.
    $playSecs = (int)round(($hours > 0 ? $hours : 1) * 3600);
    $resEnd = date('Y-m-d H:i:s', time() + max(1800, $playSecs));
    $resEndMin = ((int)date('G', strtotime($resEnd))) * 60 + (int)date('i', strtotime($resEnd));
    $nowMin = ((int)date('G')) * 60 + (int)date('i');
    $crossMidnight = $resEndMin > 1440;
    $reserved = db_row("
        SELECT customer_name, start_time, end_time FROM reservations
        WHERE table_id = ? AND status = 'confirmed'
          AND (
              (reservation_date = CURDATE() AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ? AND (HOUR(end_time) * 60 + MINUTE(end_time)) > ?)
              OR (reservation_date = CURDATE() - INTERVAL 1 DAY AND end_time > '24:00:00'
                  AND (HOUR(end_time) * 60 + MINUTE(end_time)) - 1440 > ?)
              " . ($crossMidnight ? "OR (reservation_date = CURDATE() + INTERVAL 1 DAY AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ?)" : "") . "
          )
          " . ($fromReservationId > 0 ? "AND id <> ?" : "") . "
        ORDER BY start_time LIMIT 1
    ", $fromReservationId > 0
        ? array_merge([$tableId, $resEndMin, $nowMin, $nowMin], $crossMidnight ? [$resEndMin - 1440] : [], [$fromReservationId])
        : array_merge([$tableId, $resEndMin, $nowMin, $nowMin], $crossMidnight ? [$resEndMin - 1440] : []));
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
        if ($freeHour) {
            throw new RuntimeException('Free hour claim requires selecting a paid session duration.');
        }
        $startTime = now();
        $endTime   = date('Y-m-d H:i:s', strtotime($startTime) + 3600); // default 1 hour
        db()->prepare("INSERT INTO billiard_sessions (table_id, customer_id, customer_name, start_time, end_time, karaoke, status, user_id)
                   VALUES (?,?,?,?,?, ?, 'open', ?)")
             ->execute([$tableId, $customerId > 0 ? $customerId : null, $customerName, $startTime, $endTime, $karaoke ? 1 : 0, (int)$_SESSION['user_id']]);
        db()->prepare("UPDATE tables SET status = 'occupied' WHERE id = ?")->execute([$tableId]);
        return ['session_id' => (int)db()->lastInsertId(), 'change' => 0.0, 'free_hour_applied' => false];
    }
    if ($hours < 0.5 || $hours > 12) throw new RuntimeException('Select between 30 minutes and 12 hours.');

    $freeHourApplied = false;
    if ($freeHour) {
        if (!$customerId) throw new RuntimeException('Free hour claim requires a registered customer.');
        if ($hours < 1) throw new RuntimeException('Claiming a free hour requires at least 1 hour.');
        $freeHourApplied = true;
    }
    $amount   = round($hours * $rate, 2);
    if ($freeHourApplied) $amount = round($amount - $rate, 2);
    $promoDiscount = 0.0;
    if ($promo) {
        $p = active_promo();
        if ($p) $promoDiscount = round($amount * (float)$p['discount_percent'] / 100, 2);
    }
    $freeDiscount = $freeHourApplied ? (float)$rate : 0.0;
    // Subtotal carries the full time at rate (free hour included), discount = promo + loyalty.
    $subtotal = round($amount + $freeDiscount, 2);
    $discount = round($promoDiscount + $freeDiscount, 2);
    $total    = round($subtotal - $discount, 2);
    if ($payment < $total - 0.001) throw new RuntimeException('Payment is less than the total.');

    $db = db();
    $db->beginTransaction();
    try {
        // Check + deduct stamps inside the transaction so two concurrent
        // claims can't both pass the "has 10 stamps" check.
        if ($freeHourApplied) {
            $loyalty = (int)db_value('SELECT loyalty_stamps FROM customers WHERE id = ? FOR UPDATE', [$customerId]);
            if ($loyalty < 10) throw new RuntimeException('Not enough stamps — 10 stamps required to claim a free hour.');
            // A stamp earned during the current open run (bill-out after
            // today's opening) completes the 10 only after the NEXT shop
            // opening, so it cannot be redeemed in this same open run.
            if (stamps_usable_count($customerId) < 10) throw new RuntimeException('Stamp earned this open period can only be redeemed at the next shop opening.');
            $deduct = $db->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps - 10, loyalty_completed = loyalty_completed + 1 WHERE id = ? AND loyalty_stamps >= 10');
            $deduct->execute([$customerId]);
            if ($deduct->rowCount() !== 1) throw new RuntimeException('Not enough stamps — 10 stamps required to claim a free hour.');
        }
        $startTime = now();
        $endTime   = date('Y-m-d H:i:s', strtotime($startTime) + (int)round($hours * 3600));

        $db->prepare("INSERT INTO billiard_sessions
                          (table_id, customer_id, customer_name, start_time, end_time, extended_hours, prepaid, free_hour_used, karaoke, status, user_id)
                          VALUES (?,?,?,?,?,?,?,?,?, 'open', ?)")
                      ->execute([$tableId, $customerId > 0 ? $customerId : null, $customerName, $startTime, $endTime, $hours, $total, $freeHourApplied ? 1 : 0, $karaoke ? 1 : 0, (int)$_SESSION['user_id']]);
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
        $tables = db_all("SELECT * FROM tables ORDER BY FIELD(type, 'regular', 'vip', 'kubo'), table_number");
        $sessions = [];
        $stampMap = [];
        $nextRes = [];
        foreach (db_all("SELECT table_id, customer_name, start_time FROM reservations
                         WHERE reservation_date = CURDATE() AND status IN ('confirmed','playing')
                         ORDER BY start_time") as $rr) {
            if (!isset($nextRes[(int)$rr['table_id']])) $nextRes[(int)$rr['table_id']] = $rr;
        }
        $periodStart = shop_open_period_start(time());
        $sinceMap = [];
        if ($periodStart > 0) {
            foreach (db_all("SELECT customer_id, COUNT(*) AS n FROM customer_stamps WHERE created_at >= ? GROUP BY customer_id",
                            [date('Y-m-d H:i:s', $periodStart)]) as $sr) {
                $sinceMap[(int)$sr['customer_id']] = (int)$sr['n'];
            }
        }
        // Stamp data only matters for customers currently playing — loading the
        // whole customers table on every poll is wasteful as the list grows.
        foreach (db_all("SELECT DISTINCT c.id, c.loyalty_stamps, c.loyalty_completed
                         FROM customers c
                         JOIN billiard_sessions bs ON bs.customer_id = c.id
                         WHERE bs.status = 'open'") as $c) {
            $stampMap[(int)$c['id']] = [
                (int)$c['loyalty_stamps'],
                (int)$c['loyalty_completed'],
                $periodStart > 0 ? max(0, (int)$c['loyalty_stamps'] - ($sinceMap[(int)$c['id']] ?? 0)) : (int)$c['loyalty_stamps'],
            ];
        }
        $promoMap = [];
        foreach (db_all("SELECT sa.billiard_session_id,
                                CASE WHEN COALESCE(SUM(sa.discount), 0) > CASE WHEN bs.free_hour_used = 1 THEN t.rate_per_hour ELSE 0 END THEN 1 ELSE 0 END AS promo_applied
                         FROM sales sa
                         JOIN billiard_sessions bs ON bs.id = sa.billiard_session_id
                         JOIN tables t ON t.id = bs.table_id
                         WHERE sa.billiard_session_id IS NOT NULL
                         GROUP BY sa.billiard_session_id, bs.free_hour_used, t.rate_per_hour") as $ps) {
            $promoMap[(int)$ps['billiard_session_id']] = (int)$ps['promo_applied'] === 1;
        }
        foreach (db_all("SELECT * FROM billiard_sessions WHERE status = 'open'") as $s) {
            $s['promo_applied'] = ($promoMap[(int)$s['id']] ?? 0.0) > 0;
            $stamps = !empty($s['customer_id']) && isset($stampMap[(int)$s['customer_id']]) ? $stampMap[(int)$s['customer_id']] : [0, 0, 0];
            $s['customer_stamps'] = $stamps[0];
            $s['customer_completed'] = $stamps[1];
            $s['stamps_usable'] = $stamps[2];
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

        $hours = max(0.5, min(12.0, round($durMin / 60, 2)));
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

        // Block when the added hour runs into a confirmed reservation
        // (same window logic as extending; handles >24:00 reservations).
        $curEndTs = strtotime($s['end_time']);
        $curEndMin = ((int)date('G', $curEndTs)) * 60 + (int)date('i', $curEndTs);
        $newEndMin = $curEndMin + 60;
        $crossMidnight = $newEndMin > 1440;
        $reserved = db_row("
            SELECT customer_name, start_time FROM reservations
            WHERE table_id = ? AND status = 'confirmed'
              AND (
                  (reservation_date = CURDATE() AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ? AND (HOUR(end_time) * 60 + MINUTE(end_time)) > ?)
                  OR (reservation_date = CURDATE() - INTERVAL 1 DAY AND end_time > '24:00:00'
                      AND (HOUR(end_time) * 60 + MINUTE(end_time)) - 1440 > ?)
                  " . ($crossMidnight ? "OR (reservation_date = CURDATE() + INTERVAL 1 DAY AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ?)" : "") . "
              )
            ORDER BY start_time LIMIT 1
        ", $crossMidnight
            ? [$s['table_id'], $newEndMin, $curEndMin, $curEndMin, $newEndMin - 1440]
            : [$s['table_id'], $newEndMin, $curEndMin, $curEndMin]);
        if ($reserved) {
            json_response(422, ['ok' => false, 'message' => 'Free hour would run into a reservation for ' . $reserved['customer_name'] . ' from ' . substr($reserved['start_time'], 0, 5) . '.']);
        }

        $db = db();
        $db->beginTransaction();
        try {
            // Lock the customer row, re-check the stamp balance and deduct
            // atomically so two concurrent claims cannot double-spend.
            $loyaltyRow = db_row('SELECT loyalty_stamps FROM customers WHERE id = ? FOR UPDATE', [(int)$s['customer_id']]);
            if (!$loyaltyRow || (int)$loyaltyRow['loyalty_stamps'] < 10) {
                $db->rollBack();
                json_response(422, ['ok' => false, 'message' => 'Not enough stamps — 10 stamps required to claim a free hour.']);
            }
            if (stamps_usable_count((int)$s['customer_id']) < 10) {
                $db->rollBack();
                json_response(422, ['ok' => false, 'message' => 'Stamp earned this open period can only be redeemed at the next shop opening.']);
            }
            $upd = $db->prepare("UPDATE billiard_sessions SET free_hour_used = 1, extended_hours = extended_hours + 1, end_time = DATE_ADD(end_time, INTERVAL 1 HOUR)
                                 WHERE id = ? AND free_hour_used = 0");
            $upd->execute([$sid]);
            if ($upd->rowCount() !== 1) {
                $db->rollBack();
                json_response(422, ['ok' => false, 'message' => 'Free hour already claimed for this session.']);
            }
            $deduct = $db->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps - 10, loyalty_completed = loyalty_completed + 1 WHERE id = ? AND loyalty_stamps >= 10');
            $deduct->execute([(int)$s['customer_id']]);
            if ($deduct->rowCount() !== 1) {
                $db->rollBack();
                json_response(422, ['ok' => false, 'message' => 'Not enough stamps — 10 stamps required to claim a free hour.']);
            }
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
        $promoApplied = (int)db_value("SELECT CASE WHEN COALESCE(SUM(sa.discount), 0) > CASE WHEN bs.free_hour_used = 1 THEN t.rate_per_hour ELSE 0 END THEN 1 ELSE 0 END
                                      FROM sales sa
                                      JOIN billiard_sessions bs ON bs.id = sa.billiard_session_id
                                      JOIN tables t ON t.id = bs.table_id
                                      WHERE sa.billiard_session_id = ?
                                      GROUP BY bs.free_hour_used, t.rate_per_hour", [$sid]) === 1;
        $promo = $promo && $promoApplied;
        if ($hours < 0.5 || $hours > 48) json_response(422, ['ok' => false, 'message' => 'Extend between 0.5 and 48 hours.']);

        // Block extension when the added time runs into a confirmed reservation.
        // Comparisons are minute-of-day based so >24:00 reservation windows
        // (late-night bookings that spill into the next morning) compare
        // correctly, and extensions crossing midnight check tomorrow's rows.
        $curEndSec = strtotime($s['end_time']);
        $curEndMin = ((int)date('G', $curEndSec)) * 60 + (int)date('i', $curEndSec);
        $newEndMin = $curEndMin + (int)round($hours * 60);
        $nowMin = ((int)date('G')) * 60 + (int)date('i');
        $crossMidnight = $newEndMin > 1440;
        $reserved = db_row("
            SELECT customer_name, start_time FROM reservations
            WHERE table_id = ? AND status = 'confirmed'
              AND (
                  (reservation_date = CURDATE() AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ? AND (HOUR(end_time) * 60 + MINUTE(end_time)) > ? AND (HOUR(end_time) * 60 + MINUTE(end_time)) > ?)
                  OR (reservation_date = CURDATE() - INTERVAL 1 DAY AND end_time > '24:00:00'
                      AND (HOUR(end_time) * 60 + MINUTE(end_time)) - 1440 > ?)
                  " . ($crossMidnight ? "OR (reservation_date = CURDATE() + INTERVAL 1 DAY AND (HOUR(start_time) * 60 + MINUTE(start_time)) < ?)" : "") . "
              )
            ORDER BY start_time LIMIT 1
        ", $crossMidnight
            ? [$s['table_id'], $newEndMin, $curEndMin, $nowMin, $curEndMin, $newEndMin - 1440]
            : [$s['table_id'], $newEndMin, $curEndMin, $nowMin, $curEndMin]);
        if ($reserved) {
            json_response(422, ['ok' => false, 'message' => 'Cannot extend - table is reserved for ' . $reserved['customer_name'] . ' from ' . substr($reserved['start_time'], 0, 5) . '.']);
        }

        $txt = rtrim(rtrim(sprintf('%.2f', $hours), '0'), '.');

        // Prepaid sessions: extending requires paying the additional hours up front.
        if ((float)$s['prepaid'] > 0) {
            $t = db_row('SELECT * FROM tables WHERE id = ?', [$s['table_id']]);
            $rate   = (float)$t['rate_per_hour'];
            $amount = round($hours * $rate, 2);
            $discount = 0.0;
            if ($promo) {
                $p = active_promo();
                if ($p) $discount = round($amount * (float)$p['discount_percent'] / 100, 2);
            }
            $total  = round($amount - $discount, 2);
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
                // Extensions are merged into the session's single transaction
                // (one sale row per session), so the totals stay combined.
                $db->prepare("UPDATE sales SET subtotal = subtotal + ?, discount = discount + ?, total = total + ?,
                              billiard_hours = billiard_hours + ?, billiard_amount = billiard_amount + ?
                              WHERE billiard_session_id = ? AND status = 'completed'")
                    ->execute([$amount, $discount, $total, $hours, $total, $sid]);
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

        // Prepaid sessions were already paid at start / on extend — close with
        // no new sale, UNLESS the customer played past the paid window: the
        // uncovered time is then billed now.
        if ((float)$s['prepaid'] > 0) {
            $rate = (float)$s['rate_per_hour'];
            $amount = (float)$s['prepaid'];
            $promoDiscount = (float)db_value('SELECT COALESCE(SUM(discount),0) FROM sales WHERE billiard_session_id = ?', [$sid]);
            $freeDiscount = ((int)$s['free_hour_used'] === 1) ? $rate : 0.0;
            $subtotal = $amount + $promoDiscount + $freeDiscount;
            $discount = $promoDiscount + $freeDiscount;
            $dp = (float)db_value('SELECT downpayment FROM reservations WHERE session_id = ?', [$sid]);
            $reference = db_value('SELECT reference FROM sales WHERE billiard_session_id = ? LIMIT 1', [$sid]) ?: make_reference();

            // Overstay is NOT billed — customers play until they leave; the
            // session closes on the pre-paid window plus extensions.
            $extra = 0.0;
            $overstayHours = 0.0;

            $db = db();
            $db->beginTransaction();
            try {
                $extraReference = null;
                $db->prepare("UPDATE billiard_sessions SET end_time = FROM_UNIXTIME(?), hours = ?, amount = ?, status = 'closed', user_id = ?
                              WHERE id = ?")
                    ->execute([$endTime, $hours, $amount, (int)$_SESSION['user_id'], $sid]);
                $db->prepare("UPDATE tables SET status = 'available' WHERE id = ?")->execute([$s['table_id']]);
                $db->prepare("UPDATE reservations SET status = 'completed' WHERE session_id = ? AND status = 'playing'")->execute([$sid]);
                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                json_response(500, ['ok' => false, 'message' => 'Failed to end session.']);
            }
            $award = award_loyalty_stamp($s, (float)$hours);
            $paidAtStart = round(max(0.0, (float)$s['prepaid'] - $dp), 2);
            json_response(200, ['ok' => true,
                'message' => 'Game ended. Paid ' . money((float)$s['prepaid']) . '.',
                'session' => [
                    'reference'    => $extraReference ?: $reference,
                    'table_number' => $s['table_number'],
                    'hours'        => $hours,
                    'rate'         => $rate,
                    'subtotal'     => $subtotal,
                    'discount'     => $discount,
                    'free_hour'    => $freeDiscount,
                    'promo_discount' => $promoDiscount,
                    'promo_applied'  => $promoDiscount > 0,
                    'amount'       => $amount,
                    'downpayment'  => round($dp, 2),
                    'paid_at_start' => $paidAtStart,
                    'overstay_hours' => $overstayHours,
                    'overstay_amount' => $extra,
                    'stamp_awarded' => $award['awarded'],
                    'stamps_now'   => $award['stamps_now'],
                ]]);
        }

        // Legacy unpaid session: charge the time now.
        $freeDiscount = ((int)$s['free_hour_used'] === 1) ? (float)$s['rate_per_hour'] : 0.0;
        $subtotal = round($hours * (float)$s['rate_per_hour'], 2);
        $amount  = round(max(0.0, $subtotal - $freeDiscount), 2);
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
        $award = award_loyalty_stamp($s, (float)$hours);
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
            'overstay_hours' => 0.0,
            'overstay_amount' => 0.0,
            'stamp_awarded' => $award['awarded'],
            'stamps_now'   => $award['stamps_now'],
        ]]);
    }
    break;

    case 'cancel_session': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $voidReason = trim($_POST['void_reason'] ?? '');
        $s = db_row("
            SELECT bs.*, t.table_number
            FROM billiard_sessions bs JOIN tables t ON t.id = bs.table_id
            WHERE bs.id = ? AND bs.status = 'open'
        ", [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or already closed.']);

        $db = db();
        $db->beginTransaction();
        try {
            // Void any completed sales attached to the session (and restore
            // their stock), so revenue reports and inventory stay correct.
            $saleIds = [];
            foreach (db_all("SELECT id, reference FROM sales WHERE billiard_session_id = ? AND status = 'completed'", [$sid]) as $sale) {
                $db->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$sale['id']]);
                $saleIds[] = $sale['reference'];
                foreach (db_all('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$sale['id']]) as $item) {
                    if ($item['product_id']) {
                        $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$item['qty'], $item['product_id']]);
                        $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                            ->execute([$item['product_id'], $item['qty'], "Void {$sale['reference']} (session cancelled)", (int)$_SESSION['user_id']]);
                    }
                }
                audit_log('sale_void', "Voided sale {$sale['reference']} (#{$sale['id']}) — session cancelled");
            }

            // Refund the 10 loyalty stamps if a free hour had been claimed on
            // this session (they were consumed, the hour was never used).
            $refunded = false;
            if ((int)$s['free_hour_used'] === 1 && !empty($s['customer_id'])) {
                $db->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps + 10, loyalty_completed = GREATEST(0, loyalty_completed - 1)
                              WHERE id = ?')->execute([(int)$s['customer_id']]);
                $refunded = true;
            }

            $db->prepare("UPDATE billiard_sessions SET status = 'void', void_reason = ? WHERE id = ?")->execute([$voidReason ?: null, $sid]);
            $db->prepare("UPDATE tables SET status = 'available' WHERE id = ?")->execute([$s['table_id']]);
            $db->prepare("UPDATE reservations SET status = 'cancelled' WHERE session_id = ? AND status = 'playing'")->execute([$sid]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to cancel session: ' . $ex->getMessage()]);
        }
        audit_log('session_cancel', "Session #{$sid} on table '{$s['table_number']}' cancelled" . ($saleIds ? ' — voided ' . implode(', ', $saleIds) : ''));
        json_response(200, ['ok' => true, 'message' => 'Session voided.' . ($refunded ? ' 10 loyalty stamps refunded.' : '')]);
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
        if (!in_array($type, ['regular', 'vip', 'kubo'], true)) json_response(422, ['ok' => false, 'message' => 'Invalid table type.']);

        $dup = db_value('SELECT id FROM tables WHERE table_number = ? AND id <> ?', [$number, $tableId]);
        if ($dup) json_response(409, ['ok' => false, 'message' => 'A table with that number already exists.']);

        try {
            if ($tableId > 0) {
                db()->prepare('UPDATE tables SET table_number = ?, type = ?, rate_per_hour = ? WHERE id = ?')
                    ->execute([$number, $type, $rate, $tableId]);
            } else {
                db()->prepare('INSERT INTO tables (table_number, type, rate_per_hour) VALUES (?,?,?)')
                    ->execute([$number, $type, $rate]);
            }
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1062) {
                json_response(409, ['ok' => false, 'message' => 'A table with that number already exists.']);
            }
            json_response(500, ['ok' => false, 'message' => 'Failed to save table.']);
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