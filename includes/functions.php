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
 * Formats a number as money.
 */
function money(float|int|string $amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Generates a unique sales reference (sequential TX number).
 * e.g. TX-0001, TX-0002 ...
 *
 * Uses a dedicated sequence row so the number allocation is atomic and
 * concurrency-safe: the old SELECT MAX() + 1 approach raced — two checkout
 * requests could pick the same number and the second INSERT would blow up
 * with a duplicate-key 500.
 */
function make_reference(): string
{
    $db = db();
    // The sequence table is created by install.sql / migrate_sales_reference.sql,
    // never at runtime: CREATE TABLE inside an open transaction would implicitly
    // commit and silently split the caller's atomic unit.
    // Seed from the current max on first use, then increment atomically.
    $db->prepare("INSERT INTO seq_sales_reference (id, val)
                  SELECT 1, COALESCE(MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)), 0)
                  FROM sales
                  ON DUPLICATE KEY UPDATE val = val")->execute();
    $db->exec('UPDATE seq_sales_reference SET val = LAST_INSERT_ID(val + 1) WHERE id = 1');
    $n = (int)$db->lastInsertId();
    return 'TX-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
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
 * Escape a like-search term (also escapes the LIKE wildcards so searches
 * for e.g. "100%" match literally instead of everything).
 */
function like(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
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
 * Returns the currently applicable promo (active and inside its window), or null.
 * Promos without a window are all-day. Windows may cross midnight (e.g. 22:00 -> 02:00).
 * Window boundaries are minute-precise (the old hour-only comparison could
 * start/stop promos up to 59 minutes early/late).
 */
function active_promo(): ?array
{
    $rows = db_all('SELECT * FROM promos WHERE is_active = 1 ORDER BY id ASC');
    if (!$rows) return null;
    $minOfDay = ((int)date('G')) * 60 + (int)date('i');
    foreach ($rows as $r) {
        $s = (string)($r['start_time'] ?? '');
        $e = (string)($r['end_time'] ?? '');
        if ($s !== '' && $e !== '') {
            [$sh, $sm] = array_map('intval', explode(':', $s));
            [$eh, $em] = array_map('intval', explode(':', $e));
            $start = $sh * 60 + $sm;
            $end   = $eh * 60 + $em;
            $in = $start < $end ? ($minOfDay >= $start && $minOfDay < $end) : ($minOfDay >= $start || $minOfDay < $end);
            if (!$in) continue;
        }
        return $r;
    }
    return null;
}

/**
 * Awards a loyalty stamp to a registered customer: one stamp per calendar day
 * when the session was billed for at least 1 hour (billed hours, not elapsed
 * time — a customer who pays for 2 hours but leaves early still played the
 * 2 paid hours).
 * 10 stamps = 1 free hour (claimed at session start).
 * Returns ['awarded' => bool, 'stamps_now' => ?int].
 */
function award_loyalty_stamp(array $session, float $billedHours): array
{
    // No stamp when the free hour (10 stamps) was claimed on this session.
    if (empty($session['customer_id']) || $billedHours < 1 || (int)($session['free_hour_used'] ?? 0) === 1) {
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
 * Loyalty stamps redeemable at this moment.
 *
 * Business hours come from the active shifts (Settings → Shifts). A shift
 * may cross midnight (next_day = 1). The current "open run" is the shop's
 * most recent opening — the start of the latest contiguous open window
 * before now. A stamp awarded during the current open run earns the
 * customer's 10th stamp only after the NEXT shop opening, so it does not
 * count toward a claim until then.
 *
 * Returns the balance minus stamps awarded since the current open run
 * started (0 when no shifts are configured → the shop never closes → all
 * stamps count).
 */
function stamps_usable_count(int $customerId): int
{
    $balance = (int)db_value('SELECT loyalty_stamps FROM customers WHERE id = ?', [$customerId]);
    if ($balance <= 0) return 0;
    $periodStart = shop_open_period_start(time());
    if ($periodStart <= 0) return $balance;
    $since = (int)db_value('SELECT COUNT(*) FROM customer_stamps WHERE customer_id = ? AND created_at >= ?',
        [$customerId, date('Y-m-d H:i:s', $periodStart)]);
    return max(0, $balance - $since);
}

/**
 * The shop's active shift windows, normalized to minute-of-day
 * (['start'], ['end'], ['next_day']).
 */
function shop_shift_windows(): array
{
    $rows = db_all("SELECT start_time, end_time, next_day FROM shifts WHERE is_active = 1 ORDER BY start_time, id");
    $windows = [];
    foreach ($rows as $r) {
        [$sh, $sm] = array_map('intval', explode(':', (string)$r['start_time']));
        [$eh, $em] = array_map('intval', explode(':', (string)$r['end_time']));
        $windows[] = ['start' => $sh * 60 + $sm, 'end' => $eh * 60 + $em, 'next_day' => (int)$r['next_day'] === 1];
    }
    return $windows;
}

/**
 * Whether the shop is open at unix timestamp $t. With no shifts the shop
 * is always open.
 */
function shop_open_at(int $t, array $windows = null): bool
{
    if ($windows === null) $windows = shop_shift_windows();
    if (!$windows) return true;
    $day0 = strtotime(date('Y-m-d', $t));
    foreach ($windows as $w) {
        foreach ([0, -86400] as $off) {
            $startTs = $day0 + $off + $w['start'] * 60;
            $endTs   = $day0 + $off + $w['end'] * 60 + ($w['next_day'] ? 86400 : 0);
            if ($t >= $startTs && $t < $endTs) return true;
        }
    }
    return false;
}

/**
 * Unix timestamp of the shop's most recent opening at or before $ts — the
 * start of the current open run. A shift start that happens while the shop
 * is already open (handover) is not an opening. Returns 0 when no shifts
 * are configured.
 */
function shop_open_period_start(int $ts): int
{
    $windows = shop_shift_windows();
    if (!$windows) return 0;
    $day0 = strtotime(date('Y-m-d', $ts));
    $best = 0;
    foreach ($windows as $w) {
        for ($d = -2; $d <= 0; $d++) {
            $c = $day0 + $d * 86400 + $w['start'] * 60;
            if ($c <= 0 || $c > $ts) continue;
            if (shop_open_at($c - 60, $windows)) continue;
            if ($c > $best) $best = $c;
        }
    }
    return $best;
}

/**
 * Sends an email through the Resend API using the settings configured in
 * Settings → Email (API key + sender address). Returns
 * ['ok' => true, 'message' => ...] on success, ['ok' => false] otherwise.
 */
function send_resend_email(string $to, string $subject, string $html): array
{
    $apiKey = get_setting('resend_api_key', '');
    $from   = get_setting('resend_from_email', '');
    if ($apiKey === '') return ['ok' => false, 'message' => 'Resend API key is not configured.'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Sender (from) email is not configured.'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Invalid recipient email address.'];

    $name = get_setting('business_name', '');
    $json = json_encode([
        'from'    => $name !== '' ? "$name <$from>" : $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') return ['ok' => false, 'message' => 'Could not reach Resend: ' . $err];
    $data = json_decode((string)$body, true);
    if ($code >= 200 && $code < 300 && !empty($data['id'])) {
        return ['ok' => true, 'message' => 'Email sent (id ' . $data['id'] . ').'];
    }
    return ['ok' => false, 'message' => 'Resend error: ' . ($data['message'] ?? "HTTP $code")];
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