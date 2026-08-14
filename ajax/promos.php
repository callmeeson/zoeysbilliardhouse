<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'list': {
        $rows = db_all('SELECT * FROM promos ORDER BY id DESC');
        foreach ($rows as &$r) {
            $r['discount_percent'] = (float)$r['discount_percent'];
            $r['is_active'] = (int)$r['is_active'];
            $r['start_time'] = $r['start_time'] !== null ? substr($r['start_time'], 0, 5) : '';
            $r['end_time'] = $r['end_time'] !== null ? substr($r['end_time'], 0, 5) : '';
        }
        unset($r);
        json_response(200, ['ok' => true, 'promos' => $rows]);
    }
    break;

    case 'save': {
        require_superadmin();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $percent = (float)($_POST['discount_percent'] ?? 0);
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end = trim((string)($_POST['end_time'] ?? ''));
        $isActive = (int)($_POST['is_active'] ?? 0) === 1;

        if ($name === '') json_response(422, ['ok' => false, 'message' => 'Promo name is required.']);
        if ($percent <= 0 || $percent > 100) json_response(422, ['ok' => false, 'message' => 'Discount must be between 1 and 100%.']);
        if (($start === '') !== ($end === '')) json_response(422, ['ok' => false, 'message' => 'Set both a start and end time, or leave both empty for all-day.']);

        $startDb = $start !== '' ? substr($start, 0, 5) . ':00' : null;
        $endDb   = $end !== '' ? substr($end, 0, 5) . ':00' : null;

        $db = db();
        if ($id > 0) {
            $db->prepare('UPDATE promos SET name=?, discount_percent=?, start_time=?, end_time=?, is_active=? WHERE id=?')
                ->execute([$name, $percent, $startDb, $endDb, $isActive ? 1 : 0, $id]);
        } else {
            $db->prepare('INSERT INTO promos (name, discount_percent, start_time, end_time, is_active) VALUES (?,?,?,?,?)')
                ->execute([$name, $percent, $startDb, $endDb, $isActive ? 1 : 0]);
        }
        audit_log('promo_save', "Saved promo '{$name}' ({$percent}%)");
        json_response(200, ['ok' => true, 'message' => 'Promo saved.']);
    }
    break;

    case 'delete': {
        require_superadmin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_response(422, ['ok' => false, 'message' => 'Invalid promo.']);
        $name = db_value('SELECT name FROM promos WHERE id = ?', [$id]) ?? 'Unknown';
        db()->prepare('DELETE FROM promos WHERE id = ?')->execute([$id]);
        audit_log('promo_delete', "Deleted promo '{$name}' (#{$id})");
        json_response(200, ['ok' => true, 'message' => 'Promo deleted.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
