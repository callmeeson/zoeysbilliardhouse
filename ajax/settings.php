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
        foreach ($rows as $r) {
            if ($r['skey'] === 'resend_api_key' && $r['svalue'] !== '') {
                // Never expose the raw key — only a masked hint for the UI.
                $settings[$r['skey']] = '••••••••' . substr((string)$r['svalue'], -4);
            } else {
                $settings[$r['skey']] = $r['svalue'];
            }
        }
        json_response(200, ['ok' => true, 'settings' => $settings]);
    }
    break;

    case 'save': {
        require_superadmin();
        $keys = ['business_name', 'business_address', 'business_phone', 'promo_start', 'promo_end', 'promo_label', 'resend_from_email', 'email_report_recipient', 'email_report_time', 'email_report_type'];
        $db = db();
        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) continue;
            $value = trim((string)$_POST[$key]);
            if ($key === 'resend_from_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                json_response(422, ['ok' => false, 'message' => 'The sender (from) email address is not valid.']);
            }
            if ($key === 'email_report_recipient' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                json_response(422, ['ok' => false, 'message' => 'The report recipient email address is not valid.']);
            }
            if ($key === 'email_report_time' && $value !== '' && !preg_match('/^\d{2}:\d{2}$/', $value)) {
                json_response(422, ['ok' => false, 'message' => 'Report time must be in HH:MM format.']);
            }
            if ($key === 'email_report_type' && !in_array($value, ['all', 'billiard', 'pos'], true)) {
                json_response(422, ['ok' => false, 'message' => 'Report type must be All, Billiard or POS.']);
            }
            $db->prepare("INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
                ->execute([$key, $value]);
        }
        // Toggle for the automatic report (checkbox posts 1 or nothing).
        if (array_key_exists('email_report_enabled', $_POST)) {
            $enabled = ((string)$_POST['email_report_enabled'] === '1' || (string)$_POST['email_report_enabled'] === 'on') ? '1' : '0';
            $db->prepare("INSERT INTO settings (skey, svalue) VALUES ('email_report_enabled', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
                ->execute([$enabled]);
        }
        // The API key is masked in reads; an untouched (masked) or blank value
        // keeps the current key, anything else replaces it.
        if (array_key_exists('resend_api_key', $_POST)) {
            $value = trim((string)$_POST['resend_api_key']);
            if ($value !== '' && !str_contains($value, '•')) {
                if (!str_starts_with($value, 're_')) {
                    json_response(422, ['ok' => false, 'message' => 'Resend API keys start with "re_" — check the key on resend.com.']);
                }
                $db->prepare("INSERT INTO settings (skey, svalue) VALUES ('resend_api_key', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
                    ->execute([$value]);
            }
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
        if ($f['size'] > 2 * 1024 * 1024) json_response(422, ['ok' => false, 'message' => 'Logo must be under 2 MB.']);
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
        if ($name === false || $name === null) json_response(404, ['ok' => false, 'message' => 'Shift not found.']);
        db()->prepare('DELETE FROM shifts WHERE id = ?')->execute([$id]);
        audit_log('shift_delete', "Deleted shift '{$name}' (#{$id})");
        json_response(200, ['ok' => true, 'message' => 'Shift deleted.']);
    }
    break;

    case 'send_test_email': {
        require_superadmin();
        $to = trim((string)($_POST['to'] ?? ''));
        if ($to === '') json_response(422, ['ok' => false, 'message' => 'Enter the recipient email address.']);
        $name = get_setting('business_name', '');
        $res = send_resend_email($to,
            ($name !== '' ? $name . ' — ' : '') . 'Test email',
            '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
            . '<h2 style="color:#166534;margin-bottom:8px">' . htmlspecialchars($name !== '' ? $name : 'Zoeys Billiard House') . '</h2>'
            . '<p>This is a test email from your system.</p>'
            . '<p style="color:#555">If you received this, the Resend API key and sender address are configured correctly.</p>'
            . '<p style="color:#888;font-size:12px">Sent ' . date('Y-m-d H:i') . '</p>'
            . '</div>');
        audit_log('email_test', "Test email to {$to} — " . ($res['ok'] ? 'sent' : 'failed: ' . $res['message']));
        json_response($res['ok'] ? 200 : 422, ['ok' => $res['ok'], 'message' => $res['message']]);
    }
    break;

    case 'send_report_now': {
        require_superadmin();
        $to = get_setting('email_report_recipient', '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_response(422, ['ok' => false, 'message' => 'Set a valid report recipient email first.']);
        }
        $yesterday = date('Y-m-d', strtotime('yesterday'));
        $res = send_daily_transaction_report($to, $yesterday, $yesterday, get_setting('email_report_type', 'all'));
        if ($res['ok']) {
            db()->prepare("INSERT INTO settings (skey, svalue) VALUES ('email_report_last_sent', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
                ->execute([date('Y-m-d H:i:s')]);
            audit_log('email_report', "Manual report sent to {$to} — " . $res['message']);
            json_response(200, ['ok' => true, 'message' => $res['message']]);
        }
        audit_log('email_report', "Manual report to {$to} FAILED — " . $res['message']);
        json_response(422, ['ok' => false, 'message' => $res['message']]);
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
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="zoeys_backup_' . date('Ymd_His') . '.sql"');
        try {
            $db = db();
            $dbname = DB_NAME;
            // Stream the dump in chunks instead of building one giant string in
            // memory (a large database used to exhaust PHP's memory limit).
            $chunk = 0;
            $emit = function (string $s) use (&$chunk): void {
                echo $s;
                $chunk += strlen($s);
                if ($chunk > 131072) {
                    flush();
                    $chunk = 0;
                }
            };
            // Foreign keys must be off while restoring, so the dump says so
            // (and DROP TABLE IF EXISTS before every CREATE makes restores
            // idempotent).
            $emit("-- Zoeys Billiard House Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
            $emit("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `$dbname`;\n\n");
            $emit("SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $emit("DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");

                // Stream rows unbuffered so only one row is in memory at a time.
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $rows = $db->query("SELECT * FROM `$table`");
                    foreach ($rows as $row) {
                        $cols = array_keys($row);
                        $vals = array_map(static fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($row));
                        $emit("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n");
                    }
                } finally {
                    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                $emit("\n");
            }
            $emit("SET FOREIGN_KEY_CHECKS=1;\n");
            audit_log('backup', 'Database backup downloaded');
            $emit('');
            exit;
        } catch (Throwable $ex) {
            json_response(500, ['ok' => false, 'message' => 'Backup failed: ' . $ex->getMessage()]);
        }
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
