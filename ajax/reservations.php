<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'list': {
        $date = $_GET['date'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? '';
        $params = [$date];
        $sql = "SELECT r.*, t.table_number, t.rate_per_hour
                FROM reservations r JOIN tables t ON t.id = r.table_id
                WHERE r.reservation_date = ?";
        if ($status !== '' && in_array($status, ['playing', 'confirmed', 'no_show', 'cancelled', 'rescheduled', 'completed'], true)) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY r.start_time";
        json_response(200, ['ok' => true, 'reservations' => db_all($sql, $params)]);
    }
    break;

    case 'tables_available': {
        $date = $_GET['date'] ?? date('Y-m-d');
        $sTime = $_GET['start_time'] ?? '00:00';
        $hours = (float)($_GET['hours'] ?? 0);
        $excludeId = (int)($_GET['exclude_id'] ?? 0);
        if ($hours > 0) {
            [$sh, $sm] = array_map('intval', explode(':', $sTime));
            $totalMin = $sh * 60 + $sm + (int)round($hours * 60);
            $sEnd = sprintf('%02d:%02d', intdiv($totalMin, 60), $totalMin % 60);
        } else {
            $sEnd = $_GET['end_time'] ?? '23:59';
        }
        $params = [$date, $sEnd, $sTime];
        if ($excludeId > 0) $params[] = $excludeId;
        $rows = db_all("
            SELECT t.id, t.table_number, t.rate_per_hour, t.status,
                   (SELECT COUNT(*) FROM reservations r
                    WHERE r.table_id = t.id AND r.reservation_date = ?
                      AND r.status IN ('playing','confirmed')
                      AND r.start_time < ? AND r.end_time > ?
                      " . ($excludeId > 0 ? "AND r.id <> ?" : "") . ") AS conflict
            FROM tables t
            WHERE t.status <> 'maintenance'
            ORDER BY t.table_number
        ", $params);
        $tables = array_map(static function ($r) {
            $r['available'] = $r['conflict'] == 0;
            unset($r['conflict']);
            return $r;
        }, $rows);
        json_response(200, ['ok' => true, 'tables' => $tables]);
    }
    break;

    case 'create':
    case 'save': {
        $id = (int)($_POST['id'] ?? 0);
        $isWalkIn = (int)($_POST['is_walkin'] ?? 1) === 1;
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customer = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['customer_phone'] ?? '');
        $tableId = (int)($_POST['table_id'] ?? 0);
        $date = $_POST['reservation_date'] ?? '';
        $start = $_POST['start_time'] ?? '';
        $end = $_POST['end_time'] ?? '';
        $hours = (float)($_POST['hours'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $downpayment = (float)($_POST['downpayment'] ?? 0);

        // Duration-based booking: compute end_time from start + hours.
        // End times may exceed 24:00 (e.g. 10:00 PM + 2h = 24:00 → 12:00 AM next day)
        // so TIME-overflow values are kept for comparison and display.
        if ($hours > 0) {
            if ($hours < 0.5 || $hours > 12) {
                json_response(422, ['ok' => false, 'message' => 'Select between 30 minutes and 12 hours.']);
            }
            if (!preg_match('/^\d{2}:\d{2}/', $start)) {
                json_response(422, ['ok' => false, 'message' => 'All required fields must be filled.']);
            }
            [$sh, $sm] = array_map('intval', explode(':', $start));
            $totalMin = $sh * 60 + $sm + (int)round($hours * 60);
            $end = sprintf('%02d:%02d', intdiv($totalMin, 60), $totalMin % 60);
        }

        if ($id > 0) {
            $cur = db_value('SELECT status FROM reservations WHERE id = ?', [$id]);
            if ($cur === 'playing') json_response(422, ['ok' => false, 'message' => 'Cannot edit a reservation with an active session.']);
            if ($cur === 'completed') json_response(422, ['ok' => false, 'message' => 'Cannot edit a completed reservation.']);
        }

        if (!$isWalkIn && $customerId > 0) {
            $cust = db_row('SELECT * FROM customers WHERE id = ? AND is_active = 1', [$customerId]);
            if (!$cust) {
                json_response(422, ['ok' => false, 'message' => 'Customer not found.']);
            }
            $customer = $cust['name'];
            $phone = (string)($cust['phone'] ?? $phone);
        }

        if ($customer === '' || $tableId <= 0 || !$date || !$start || !$end) {
            json_response(422, ['ok' => false, 'message' => 'All required fields must be filled.']);
        }
        if ($start >= $end) {
            json_response(422, ['ok' => false, 'message' => 'End time must be after start time.']);
        }
        if ($downpayment < 0) {
            json_response(422, ['ok' => false, 'message' => 'Downpayment cannot be negative.']);
        }

        $table = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$table || $table['status'] === 'maintenance') {
            json_response(422, ['ok' => false, 'message' => 'This table is not available.']);
        }

        // overlapping reservation check (exclude self when editing)
        $overlap = db_value("
            SELECT COUNT(*) FROM reservations
            WHERE table_id = ? AND reservation_date = ?
              AND status IN ('playing','confirmed')
              AND start_time < ? AND end_time > ?
              " . ($id > 0 ? "AND id <> ?" : "") . "
        ", $id > 0 ? [$tableId, $date, $end, $start, $id] : [$tableId, $date, $end, $start]);
        if ((int)$overlap > 0) {
            json_response(409, ['ok' => false, 'message' => 'Time slot overlaps an existing reservation.']);
        }

        if ($id > 0) {
            db()->prepare("UPDATE reservations SET customer_name=?, customer_phone=?, customer_id=?, is_walkin=?,
                           table_id=?, reservation_date=?, start_time=?, end_time=?, notes=?, downpayment=? WHERE id=?")
                ->execute([$customer, $phone, $isWalkIn ? null : $customerId, $isWalkIn ? 1 : 0,
                           $tableId, $date, $start, $end, $notes, round($downpayment, 2), $id]);
        } else {
            db()->prepare("INSERT INTO reservations (customer_name, customer_phone, customer_id, is_walkin, table_id,
                           reservation_date, start_time, end_time, notes, downpayment, status, created_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?,'confirmed',?)")
                ->execute([$customer, $phone, $isWalkIn ? null : $customerId, $isWalkIn ? 1 : 0,
                           $tableId, $date, $start, $end, $notes, round($downpayment, 2), (int)$_SESSION['user_id']]);
        }
        json_response(200, ['ok' => true, 'message' => 'Reservation saved.']);
    }
    break;

    case 'status': {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['playing', 'confirmed', 'no_show', 'cancelled', 'rescheduled', 'completed'], true)) {
            json_response(422, ['ok' => false, 'message' => 'Invalid status.']);
        }
        $cur = db_value('SELECT status FROM reservations WHERE id = ?', [$id]);
        if ($cur === 'playing') json_response(422, ['ok' => false, 'message' => 'Status is managed by the active session.']);
        if ($cur === 'completed') json_response(422, ['ok' => false, 'message' => 'Cannot change the status of a completed reservation.']);
        db()->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$status, $id]);
        json_response(200, ['ok' => true, 'message' => 'Status updated.']);
    }
    break;

    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        $cur = db_value('SELECT status FROM reservations WHERE id = ?', [$id]);
        if ($cur === 'playing') json_response(422, ['ok' => false, 'message' => 'Cannot delete a reservation with an active session.']);
        if ($cur === 'completed') json_response(422, ['ok' => false, 'message' => 'Cannot delete a completed reservation.']);
        db()->prepare('DELETE FROM reservations WHERE id = ?')->execute([$id]);
        json_response(200, ['ok' => true, 'message' => 'Reservation deleted.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}