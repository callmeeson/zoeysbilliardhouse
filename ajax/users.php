<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_admin();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'list': {
        $isSuper = ($_SESSION['role'] ?? '') === 'superadmin';
        $users = db_all("
            SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.created_at, u.last_login,
                   (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales_count
            FROM users u
            " . ($isSuper ? '' : "WHERE u.role <> 'superadmin'") . "
            ORDER BY u.id
        ");
        $users = array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'full_name' => $r['full_name'],
            'role' => $r['role'],
            'is_active' => (int)$r['is_active'],
            'created_at' => $r['created_at'],
            'last_login' => $r['last_login'],
            'sales_count' => (int)$r['sales_count'],
        ], $users);
        json_response(200, ['ok' => true, 'users' => $users, 'current_id' => (int)$_SESSION['user_id']]);
    }
    break;

    case 'save': {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $roleInput = $_POST['role'] ?? 'staff';
        $isSuper = ($_SESSION['role'] ?? '') === 'superadmin';
        $role = in_array($roleInput, ['admin', 'staff', 'superadmin'], true) ? $roleInput : 'staff';
        $password = $_POST['password'] ?? '';

        if ($username === '' || $fullName === '') {
            json_response(422, ['ok' => false, 'message' => 'Username and full name are required.']);
        }
        if (preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username) !== 1) {
            json_response(422, ['ok' => false, 'message' => 'Username must be 3-30 characters: letters, numbers, _ or .']);
        }
        $exists = db_value('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        if ((int)$exists > 0) {
            json_response(409, ['ok' => false, 'message' => 'Username is already taken.']);
        }

        if ($id > 0) {
            $old = db_row('SELECT role, is_active FROM users WHERE id = ?', [$id]);
            if (!$old) json_response(404, ['ok' => false, 'message' => 'User not found.']);

            // Only superadmins may assign the superadmin role or edit superadmin accounts.
            if (($role === 'superadmin' || $old['role'] === 'superadmin') && !$isSuper) {
                json_response(403, ['ok' => false, 'message' => 'Only superadmins can manage superadmin accounts.']);
            }

            // cannot demote the last active admin / superadmin
            if (($old['role'] === 'admin' || $old['role'] === 'superadmin') && $role !== $old['role'] && (int)$id === (int)$_SESSION['user_id']) {
                json_response(422, ['ok' => false, 'message' => 'You cannot change your own role.']);
            }

            if ($password !== '') {
                if (strlen($password) < 6) json_response(422, ['ok' => false, 'message' => 'Password must be at least 6 characters.']);
                db()->prepare('UPDATE users SET username=?, full_name=?, role=?, password=? WHERE id=?')
                    ->execute([$username, $fullName, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
            } else {
                db()->prepare('UPDATE users SET username=?, full_name=?, role=? WHERE id=?')
                    ->execute([$username, $fullName, $role, $id]);
            }
        } else {
            if ($role === 'superadmin' && !$isSuper) {
                json_response(403, ['ok' => false, 'message' => 'Only superadmins can create superadmin accounts.']);
            }
            if (strlen($password) < 6) json_response(422, ['ok' => false, 'message' => 'Password must be at least 6 characters.']);
            db()->prepare('INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)')
                ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $role]);
        }
        audit_log('user_save', "Saved user '{$username}' (role: {$role})");
        json_response(200, ['ok' => true, 'message' => 'User saved.']);
    }
    break;

    case 'status': {
        $id = (int)($_POST['id'] ?? 0);
        $isSuper = ($_SESSION['role'] ?? '') === 'superadmin';
        if ($id === (int)$_SESSION['user_id']) {
            json_response(422, ['ok' => false, 'message' => 'You cannot disable your own account.']);
        }
        $targetRole = db_value('SELECT role FROM users WHERE id = ?', [$id]);
        if ($targetRole === 'superadmin' && !$isSuper) {
            json_response(403, ['ok' => false, 'message' => 'Only superadmins can change superadmin accounts.']);
        }
        $state = (int)($_POST['is_active'] ?? 0);
        if ($state === 0) {
            $adminCount = (int)db_value("SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin') AND is_active = 1 AND id <> ?", [$id]);
            if ($targetRole === 'admin' && $adminCount === 0) {
                json_response(422, ['ok' => false, 'message' => 'At least one active admin is required.']);
            }
            $superCount = (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND is_active = 1 AND id <> ?", [$id]);
            if ($targetRole === 'superadmin' && $superCount === 0) {
                json_response(422, ['ok' => false, 'message' => 'At least one active superadmin is required.']);
            }
        }
        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$state, $id]);
        audit_log('user_status', "Set user #{$id} active=" . ($state ? '1' : '0'));
        json_response(200, ['ok' => true, 'message' => 'User status updated.']);
    }
    break;

    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        $isSuper = ($_SESSION['role'] ?? '') === 'superadmin';
        if ($id === (int)$_SESSION['user_id']) {
            json_response(422, ['ok' => false, 'message' => 'You cannot delete your own account.']);
        }
        $targetRole = db_value('SELECT role FROM users WHERE id = ?', [$id]);
        if ($targetRole === 'superadmin' && !$isSuper) {
            json_response(403, ['ok' => false, 'message' => 'Only superadmins can delete superadmin accounts.']);
        }
        if ($targetRole === 'superadmin') {
            $superCount = (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND id <> ?", [$id]);
            if ($superCount === 0) {
                json_response(422, ['ok' => false, 'message' => 'Cannot delete the last superadmin account.']);
            }
        }
        if ($targetRole === 'admin') {
            $adminCount = (int)db_value("SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin') AND id <> ?", [$id]);
            if ($adminCount === 0) {
                json_response(422, ['ok' => false, 'message' => 'Cannot delete the last admin account.']);
            }
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        audit_log('user_delete', "Deleted user #{$id} (role: {$targetRole})");
        json_response(200, ['ok' => true, 'message' => 'User deleted.']);
    }
    break;

    case 'reset_password': {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $isSuper = ($_SESSION['role'] ?? '') === 'superadmin';

        $target = db_row('SELECT username, role FROM users WHERE id = ?', [$id]);
        if (!$target) json_response(404, ['ok' => false, 'message' => 'User not found.']);
        if ($target['role'] === 'superadmin' && !$isSuper) {
            json_response(403, ['ok' => false, 'message' => 'Only superadmins can reset superadmin passwords.']);
        }
        if (strlen($password) < 6) {
            json_response(422, ['ok' => false, 'message' => 'Password must be at least 6 characters.']);
        }
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        audit_log('user_password', "Reset password for user '{$target['username']}'");
        json_response(200, ['ok' => true, 'message' => 'Password updated.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}