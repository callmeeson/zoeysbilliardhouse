<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
// Reads (get / shifts) are available to any logged-in user — receipts need the
// business name for staff cashiers. Writes stay superadmin-only (guarded per case).
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'get': {
        $rows = db_all("SELECT skey, svalue FROM settings");
        $settings = [];
        foreach ($rows as $r) $settings[$r['skey']] = $r['svalue'];
        json_response(200, ['ok' => true, 'settings' => $settings]);
    }
    break;

    case 'save': {
        require_superadmin();
        $keys = ['business_name', 'business_address', 'business_phone', 'promo_start', 'promo_end', 'promo_label'];
        $db = db();
        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) continue;
            $value = trim((string)$_POST[$key]);
            $db->prepare("INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
                ->execute([$key, $value]);
        }
        audit_log('settings_save', 'System settings updated');
        json_response(200, ['ok' => true, 'message' => 'Settings saved.']);
    }
    break;

    case 'logo': {
        require_superadmin();
        $dir = dirname(__DIR__) . '/uploads';
        if (isset($_POST['remove'])) {
            @unlink("$dir/" . db_value('SELECT svalue FROM settings WHERE skey = ?', ['business_logo']));
            db()->prepare("INSERT INTO settings (skey, svalue) VALUES ('business_logo','') ON DUPLICATE KEY UPDATE svalue = ''")->execute();
            audit_log('settings_logo', 'Receipt logo removed');
            json_response(200, ['ok' => true, 'message' => 'Logo removed.']);
        }
        $f = $_FILES['logo'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            json_response(422, ['ok' => false, 'message' => 'No logo file received.']);
        }
        $info = @getimagesize($f['tmp_name']);
        if (!$info) json_response(422, ['ok' => false, 'message' => 'Not a valid image file.']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']] ?? null;
        if (!$ext) json_response(422, ['ok' => false, 'message' => 'Logo must be PNG, JPG or WEBP.']);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = 'logo.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
            json_response(500, ['ok' => false, 'message' => 'Could not save the logo file.']);
        }
        db()->prepare("INSERT INTO settings (skey, svalue) VALUES ('business_logo', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
            ->execute([$name]);
        audit_log('settings_logo', 'Receipt logo updated');
        json_response(200, ['ok' => true, 'message' => 'Logo updated.']);
    }
    break;

    case 'shifts': {
        $rows = db_all("SELECT id, name, start_time, end_time, next_day, is_active FROM shifts ORDER BY start_time, id");
        json_response(200, ['ok' => true, 'shifts' => $rows]);
    }
    break;

    case 'save_shift': {
        require_superadmin();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $start = $_POST['start_time'] ?? '';
        $end = $_POST['end_time'] ?? '';
        $nextDay = (int)($_POST['next_day'] ?? 0) === 1;
        if ($name === '') json_response(422, ['ok' => false, 'message' => 'Shift name is required.']);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            json_response(422, ['ok' => false, 'message' => 'Valid start and end times are required.']);
        }
        $start = substr($start, 0, 5) . ':00';
        $end = substr($end, 0, 5) . ':00';

        if ($id > 0) {
            db()->prepare('UPDATE shifts SET name=?, start_time=?, end_time=?, next_day=? WHERE id=?')
                ->execute([$name, $start, $end, $nextDay ? 1 : 0, $id]);
        } else {
            db()->prepare('INSERT INTO shifts (name, start_time, end_time, next_day) VALUES (?,?,?,?)')
                ->execute([$name, $start, $end, $nextDay ? 1 : 0]);
        }
        audit_log('shift_save', "Saved shift '{$name}' ({$start} – {$end}" . ($nextDay ? ' next day' : '') . ")");
        json_response(200, ['ok' => true, 'message' => 'Shift saved.']);
    }
    break;

    case 'delete_shift': {
        require_superadmin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_response(422, ['ok' => false, 'message' => 'Invalid shift.']);
        $name = db_value('SELECT name FROM shifts WHERE id = ?', [$id]);
        db()->prepare('DELETE FROM shifts WHERE id = ?')->execute([$id]);
        audit_log('shift_delete', "Deleted shift '{$name}' (#{$id})");
        json_response(200, ['ok' => true, 'message' => 'Shift deleted.']);
    }
    break;

    case 'sysinfo': {
        require_superadmin();
        $tables = (int)db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
        $settingCount = (int)db_value('SELECT COUNT(*) FROM settings');
        $logoFile = db_value('SELECT svalue FROM settings WHERE skey = ?', ['business_logo']);
        $logoExists = $logoFile !== '' && is_file(dirname(__DIR__) . '/uploads/' . $logoFile);
        json_response(200, [
            'ok' => true,
            'info' => [
                'php_version' => PHP_VERSION,
                'db_name' => DB_NAME,
                'table_count' => $tables,
                'settings_count' => $settingCount,
                'logo_file' => $logoFile ?: null,
                'logo_exists' => $logoExists,
            ],
        ]);
    }
    break;

    case 'backup': {
        require_superadmin();
        try {
            $db = db();
            $dbname = DB_NAME;
            $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $sql = "-- Zoeys Billiard House Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sql .= "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `$dbname`;\n\n";

            foreach ($tables as $table) {
                $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n";

                $rows = $db->query("SELECT * FROM `$table`");
                foreach ($rows as $row) {
                    $cols = array_keys($row);
                    $vals = array_map(static fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($row));
                    $sql .= "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
                }
                $sql .= "\n";
            }
            audit_log('backup', 'Database backup downloaded');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="zoeys_backup_' . date('Ymd_His') . '.sql"');
            echo $sql;
            exit;
        } catch (Throwable $ex) {
            json_response(500, ['ok' => false, 'message' => 'Backup failed: ' . $ex->getMessage()]);
        }
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
