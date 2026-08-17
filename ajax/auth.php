<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'login': {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            json_response(422, ['ok' => false, 'message' => 'Please enter your username and password.']);
        }

        // DB-backed throttle keyed by IP + username, plus a per-IP counter so
        // password-spraying many usernames from one address is also limited.
        try {
            $db = db();
            // prune old attempts so the table never grows unbounded
            $db->prepare('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 HOUR')->execute();

            // Per-IP throttle: stops spraying one username after another from
            // the same address (each username used to have its own counter).
            $ipRecent = (int)db_value(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE',
                [$ip]
            );
            if ($ipRecent >= 20) {
                $ipLastAt = db_value('SELECT MAX(attempted_at) FROM login_attempts WHERE ip = ?', [$ip]);
                $ipWait = $ipLastAt ? max(1, 60 - (time() - strtotime((string)$ipLastAt))) : 60;
                json_response(429, ['ok' => false, 'message' => 'Too many failed attempts. Try again in ' . $ipWait . 's.']);
            }

            $recent = (int)db_value(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE',
                [$ip, $username]
            );
            if ($recent >= 5) {
                $lastAt = db_value('SELECT MAX(attempted_at) FROM login_attempts WHERE ip = ? AND username = ?', [$ip, $username]);
                $wait = $lastAt ? max(1, 60 - (time() - strtotime((string)$lastAt))) : 60;
                json_response(429, ['ok' => false, 'message' => 'Too many failed attempts. Try again in ' . $wait . 's.']);
            }
            $login = attempt_login($username, $password);
            if ($login === 'ok') {
                $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                $u = current_user();
                unset($u['password']); // never expose the password hash
                json_response(200, ['ok' => true, 'user' => $u]);
            }
            if ($login === 'disabled') {
                // Valid credentials but the account is off — tell them clearly
                // instead of the generic "invalid credentials" message.
                json_response(403, ['ok' => false, 'message' => 'Your account has been disabled. Please contact the administrator.']);
            }
            $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?,?)')->execute([$ip, $username]);
        } catch (Throwable $ex) {
            // Fail closed: if the throttle table is missing, locked, or the
            // throttle queries error, reject the login instead of falling
            // through to an unthrottled attempt_login().
            json_response(503, ['ok' => false, 'message' => 'Login temporarily unavailable. Please try again shortly.']);
        }
        json_response(401, ['ok' => false, 'message' => 'Invalid username or password.']);
    }
    break;

    case 'me': {
        $user = current_user();
        if (!$user) {
            json_response(401, ['ok' => false, 'message' => 'Not authenticated.']);
        }
        // never expose the password hash
        unset($user['password']);
        json_response(200, ['ok' => true, 'user' => $user]);
    }
    break;

    case 'logout': {
        audit_log('logout', 'User logged out');
        logout();
        json_response(200, ['ok' => true, 'message' => 'Logged out.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
