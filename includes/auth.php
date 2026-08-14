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
 * Logs a user in from username + password.
 */
function attempt_login(string $username, string $password): bool
{
    $user = db_row('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1', [$username]);
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        audit_log('login', 'User logged in', (int)$user['id']);
        return true;
    }
    return false;
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

function role_badge(string $role): string
{
    return match ($role) {
        'superadmin' => '<span class="badge bg-dark">Super Admin</span>',
        'admin'      => '<span class="badge bg-danger">Admin</span>',
        default      => '<span class="badge bg-secondary">Staff</span>',
    };
}

/**
 * Writes an entry to the audit log.
 */
function audit_log(string $action, ?string $detail = null, ?int $userId = null): void
{
    try {
        db()->prepare('INSERT INTO audit_logs (user_id, action, detail) VALUES (?,?,?)')
            ->execute([$userId ?? ($_SESSION['user_id'] ?? null), $action, $detail]);
    } catch (Throwable $e) {
        // audit logging must never break the main flow
    }
}