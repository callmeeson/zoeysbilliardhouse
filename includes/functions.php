<?php
declare(strict_types=1);

/**
 * Outputs a JSON response and exits.
 */
function json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Escapes output for HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Formats a number as money.
 */
function money(float|int|string $amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Generates a unique sales reference (daily-reset sequential TX number).
 * e.g. TX-0001, TX-0002 ... resets to TX-0001 the next day.
 */
function make_reference(): string
{
    // Based on the highest existing number, so deleted/gapped rows can't collide
    // (COUNT-based refs collide forever once a row is removed - the retry loop
    // would just regenerate the same number).
    $max = (int)db_value("SELECT MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) FROM sales");
    return 'TX-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Query helper that returns all rows.
 */
function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Query helper that returns one row.
 */
function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Query helper that returns one value.
 */
function db_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Escape a like-search term.
 */
function like(string $term): string
{
    return '%' . $term . '%';
}

/**
 * Basic current time (server local).
 */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Returns a system setting value (from settings table) or a default.
 */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable $e) {
            // settings table may not exist yet
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Returns the current promo window as [start_hour, end_hour] (integers).
 */
function promo_window(): array
{
    $start = (int)substr(get_setting('promo_start', '08:00'), 0, 2);
    $end   = (int)substr(get_setting('promo_end', '12:00'), 0, 2);
    return [$start, $end];
}

/**
 * Returns the currently applicable promo (active and inside its window), or null.
 * Promos without a window are all-day. Windows may cross midnight (e.g. 22:00 -> 02:00).
 */
function active_promo(): ?array
{
    $rows = db_all('SELECT * FROM promos WHERE is_active = 1 ORDER BY id ASC');
    if (!$rows) return null;
    $hour = (int)date('G');
    foreach ($rows as $r) {
        $s = (string)($r['start_time'] ?? '');
        $e = (string)($r['end_time'] ?? '');
        if ($s !== '' && $e !== '') {
            $sh = (int)substr($s, 0, 2);
            $eh = (int)substr($e, 0, 2);
            $in = $sh <= $eh ? ($hour >= $sh && $hour < $eh) : ($hour >= $sh || $hour < $eh);
            if (!$in) continue;
        }
        return $r;
    }
    return null;
}

/**
 * Awards a loyalty stamp to a registered customer: one stamp per calendar day
 * when the session lasted at least 1 hour. 10 stamps = 1 free hour (claimed at
 * session start). Returns ['awarded' => bool, 'stamps_now' => ?int].
 */
function award_loyalty_stamp(array $session, float $hours): array
{
    // No stamp when the free hour (10 stamps) was claimed on this session.
    if (empty($session['customer_id']) || $hours < 1 || (int)($session['free_hour_used'] ?? 0) === 1) {
        return ['awarded' => false, 'stamps_now' => null];
    }
    $stamp = db()->prepare('INSERT INTO customer_stamps (customer_id, stamp_date, awarded_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
    $stamp->execute([(int)$session['customer_id'], date('Y-m-d'), (int)($_SESSION['user_id'] ?? 0)]);
    if ($stamp->rowCount() === 1) {
        db()->prepare('UPDATE customers SET loyalty_stamps = loyalty_stamps + 1 WHERE id = ?')->execute([(int)$session['customer_id']]);
    }
    $now = db_value('SELECT loyalty_stamps FROM customers WHERE id = ?', [(int)$session['customer_id']]);
    return ['awarded' => $stamp->rowCount() === 1, 'stamps_now' => (int)$now];
}

/**
 * Initials for avatars, e.g. "Eson Estanislao" -> "EE".
 */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $out .= strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}