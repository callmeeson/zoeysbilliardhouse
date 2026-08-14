<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'login': {
        $now = time();
        $tries = (int)($_SESSION['login_tries'] ?? 0);
        $lockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
        if ($lockUntil > $now) {
            json_response(429, ['ok' => false, 'message' => 'Too many failed attempts. Try again in ' . ($lockUntil - $now) . 's.']);
        }
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            json_response(422, ['ok' => false, 'message' => 'Please enter your username and password.']);
        }
        if (attempt_login($username, $password)) {
            unset($_SESSION['login_tries'], $_SESSION['login_lock_until']);
            json_response(200, ['ok' => true, 'user' => current_user()]);
        }
        $tries++;
        $_SESSION['login_tries'] = $tries;
        if ($tries >= 5) {
            $_SESSION['login_lock_until'] = $now + 60;
            $_SESSION['login_tries'] = 0;
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
