<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'list': {
        $notifications = [];

        $low = db_all("
            SELECT id, name, stock, low_stock FROM products
            WHERE status = 'active' AND stock <= low_stock
            ORDER BY (stock / GREATEST(low_stock, 1)) ASC LIMIT 5
        ");
        foreach ($low as $p) {
            $notifications[] = [
                'id' => 'low-' . $p['id'],
                'type' => 'stock',
                'title' => 'Low stock: ' . $p['name'] . ' (' . (int)$p['stock'] . ' left)',
                'time' => 'Now',
            ];
        }

        $sessions = db_all("
            SELECT bs.id, bs.start_time, bs.end_time, t.table_number
            FROM billiard_sessions bs JOIN tables t ON t.id = bs.table_id
            WHERE bs.status = 'open'
            ORDER BY bs.start_time
        ");
        foreach ($sessions as $s) {
            // Last 10 minutes of paid time — staff should remind the customer.
            $remaining = strtotime($s['end_time']) - time();
            if ($remaining > 0 && $remaining <= 600) {
                $mins = max(1, (int)ceil($remaining / 60));
                $notifications[] = [
                    'id' => 'ending-' . $s['id'],
                    'type' => 'ending',
                    'title' => 'Table ' . $s['table_number'] . ' ends in ' . $mins . ' min — remind customer',
                    'time' => 'Last 10 minutes',
                ];
            }

            $mins = (int)((time() - strtotime($s['start_time'])) / 60);
            $title = 'Table ' . $s['table_number'] . ' session running';
            if ($mins >= 60) {
                $title .= ' — ' . floor($mins / 60) . 'h ' . ($mins % 60) . 'm elapsed';
            } else {
                $title .= ' — ' . max($mins, 0) . 'm elapsed';
            }
            $notifications[] = ['id' => 'sess-' . $s['id'], 'type' => 'session', 'title' => $title, 'time' => 'Live'];
        }

        $res = db_all("
            SELECT r.id, r.customer_name, r.start_time, t.table_number
            FROM reservations r JOIN tables t ON t.id = r.table_id
            WHERE r.reservation_date = ? AND r.status IN ('pending', 'confirmed')
            ORDER BY r.start_time
        ", [date('Y-m-d')]);
        foreach ($res as $r) {
            $notifications[] = [
                'id' => 'res-' . $r['id'],
                'type' => 'reservation',
                'title' => 'Reservation ' . substr($r['start_time'], 0, 5) . ' — ' . $r['customer_name'] . ' (Table ' . $r['table_number'] . ')',
                'time' => 'Today',
            ];
        }

        json_response(200, ['ok' => true, 'notifications' => $notifications]);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
