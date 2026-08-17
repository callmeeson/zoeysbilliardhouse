<?php
declare(strict_types=1);

/**
 * True when the current request expects JSON (the SPA ajax calls).
 */
function is_json_request(): bool
{
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $acc = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    return $xrw === 'xmlhttprequest'
        || strpos($acc, 'application/json') !== false
        || strpos($uri, '/ajax/') !== false;
}

/**
 * Guards a page: redirects to login when not authenticated.
 * JSON/SPA requests get a clean 401 instead of a redirect.
 *
 * Also re-validates the account on every request against the DB, so a user
 * who is deactivated, deleted, or demoted loses access immediately (the old
 * code trusted the session snapshot forever, leaving stale privileges until
 * the session expired).
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        if (is_json_request()) {
            json_response(401, ['ok' => false, 'message' => 'Session expired. Please sign in again.']);
        }
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
    $u = db_row('SELECT id, role, is_active FROM users WHERE id = ?', [$_SESSION['user_id']]);
    if (!$u || (int)$u['is_active'] !== 1) {
        logout();
        if (is_json_request()) {
            json_response(401, ['ok' => false, 'message' => 'Your account has been deactivated. Please contact the administrator.']);
        }
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
    // Keep the session role in sync with the live role (e.g. demoted admins
    // lose their admin access on the very next request).
    if (($_SESSION['role'] ?? '') !== $u['role']) {
        $_SESSION['role'] = $u['role'];
    }
}

/**
 * True when the current user is a superadmin.
 */
function is_superadmin(): bool
{
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

/**
 * True when the current user is an admin OR superadmin.
 */
function is_admin(): bool
{
    $r = $_SESSION['role'] ?? '';
    return $r === 'admin' || $r === 'superadmin';
}

/**
 * Guards a page: admins and superadmins may continue. Others are sent to the tables page.
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        if (is_json_request()) {
            json_response(403, ['ok' => false, 'message' => 'Admin privileges required.']);
        }
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * Guards a page: only superadmins may continue. Others are sent to the dashboard.
 */
function require_superadmin(): void
{
    require_login();
    if (!is_superadmin()) {
        if (is_json_request()) {
            json_response(403, ['ok' => false, 'message' => 'Super admin privileges required.']);
        }
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

/**
 * Current logged-in user, or null.
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $user = db_row('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
    }
    return $user;
}

/**
 * Attempts a login and reports the outcome:
 *   'ok'       — credentials valid and account active (session started)
 *   'disabled' — credentials valid but the account has been disabled
 *   'invalid'  — username/password mismatch or unknown user
 */
function attempt_login(string $username, string $password): string
{
    $user = db_row('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
    if (!$user || !password_verify($password, $user['password'])) {
        return 'invalid';
    }
    if ((int)$user['is_active'] !== 1) {
        return 'disabled';
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    audit_log('login', 'User logged in', (int)$user['id']);
    return 'ok';
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Writes an entry to the audit log.
 * Snapshot of the username + role is stored so the entry stays attributable
 * even after the user is deleted (the old join-based view leaked deleted
 * superadmin actions to admins because the join turned NULL).
 */
function audit_log(string $action, ?string $detail = null, ?int $userId = null): void
{
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $name = null;
        $role = null;
        if ($uid !== null) {
            static $identity = [];
            if (!array_key_exists($uid, $identity)) {
                $u = db_row('SELECT username, role FROM users WHERE id = ?', [$uid]);
                $identity[$uid] = $u ? [$u['username'], $u['role']] : [null, null];
            }
            [$name, $role] = $identity[$uid];
        }
        db()->prepare('INSERT INTO audit_logs (user_id, action, detail, user_name, user_role) VALUES (?,?,?,?,?)')
            ->execute([$uid, $action, $detail, $name, $role]);
    } catch (Throwable $e) {
        // audit logging must never break the main flow
    }
}