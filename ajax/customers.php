<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'search': {
        $q = trim($_GET['q'] ?? '');
        $sql = "SELECT id, name, phone, loyalty_stamps, loyalty_completed FROM customers WHERE is_active = 1";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (name LIKE ? OR phone LIKE ?)";
            $params = [like($q), like($q)];
        }
        $sql .= " ORDER BY name LIMIT 20";
        $rows = db_all($sql, $params);
        foreach ($rows as &$r) {
            $r['initials'] = initials($r['name']);
        }
        unset($r);
        json_response(200, ['ok' => true, 'customers' => $rows]);
    }
    break;

    case 'save': {
        if (!is_admin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Admins only.']);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '') json_response(422, ['ok' => false, 'message' => 'Customer name is required.']);
        if (db_row('SELECT id FROM customers WHERE name = ? AND id <> ?', [$name, $id])) {
            json_response(422, ['ok' => false, 'message' => 'A customer with that name already exists.']);
        }
        if ($id > 0) {
            db()->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone !== '' ? $phone : null, $id]);
        } else {
            db()->prepare('INSERT INTO customers (name, phone, is_active) VALUES (?, ?, 1)')->execute([$name, $phone !== '' ? $phone : null]);
            $id = (int)db()->lastInsertId();
        }
        json_response(200, ['ok' => true, 'message' => 'Customer saved.', 'customer' => db_row('SELECT id, name, phone, loyalty_stamps, loyalty_completed FROM customers WHERE id = ?', [$id])]);
    }
    break;

    case 'adjust_stamps': {
        if (!is_superadmin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Superadmin only.']);
        $id = (int)($_POST['customer_id'] ?? 0);
        $new = max(0, (int)($_POST['stamps'] ?? 0));
        $c = db_row('SELECT id, name, loyalty_stamps FROM customers WHERE id = ?', [$id]);
        if (!$c) json_response(404, ['ok' => false, 'message' => 'Customer not found.']);
        db()->prepare('UPDATE customers SET loyalty_stamps = ? WHERE id = ?')->execute([$new, $id]);
        audit_log('loyalty_adjust', "Customer '{$c['name']}' (#{$id}) stamps {$c['loyalty_stamps']} -> {$new}");
        json_response(200, ['ok' => true, 'message' => 'Stamps updated to ' . $new . '.', 'stamps' => $new]);
    }
    break;

    case 'delete': {
        if (!is_admin()) json_response(403, ['ok' => false, 'message' => 'Access denied. Admins only.']);
        $id = (int)($_POST['id'] ?? 0);
        $c = db_row('SELECT id, name FROM customers WHERE id = ?', [$id]);
        if (!$c) json_response(404, ['ok' => false, 'message' => 'Customer not found.']);
        if (db_value('SELECT COUNT(*) FROM billiard_sessions WHERE customer_id = ? AND status = "open"', [$id]) > 0) {
            json_response(422, ['ok' => false, 'message' => 'This customer has an active session. End it first.']);
        }
        db()->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$id]);
        audit_log('customer_delete', "Customer '{$c['name']}' (#{$id}) deleted");
        json_response(200, ['ok' => true, 'message' => 'Customer removed.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
