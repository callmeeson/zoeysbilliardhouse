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
 *
 * Optional $attachments: array of ['filename' => ..., 'content' => base64,
 * 'type' => mime] sent as Resend attachments.
 */
function send_resend_email(string $to, string $subject, string $html, array $attachments = []): array
{
    $apiKey = get_setting('resend_api_key', '');
    $from   = get_setting('resend_from_email', '');
    if ($apiKey === '') return ['ok' => false, 'message' => 'Resend API key is not configured.'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Sender (from) email is not configured.'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Invalid recipient email address.'];

    $name = get_setting('business_name', '');
    $payload = [
        'from'    => $name !== '' ? "$name <$from>" : $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($attachments) {
        $payload['attachments'] = array_values($attachments);
    }
    $json = json_encode($payload);

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

/**
 * Formats a datetime as 12-hour text, e.g. "2026-08-18 02:30:45 PM".
 */
function format_12h(?string $dt): string
{
    if ($dt === null || $dt === '') return '';
    $ts = strtotime($dt);
    if ($ts === false) return '';
    return date('Y-m-d h:i:s A', $ts);
}

/**
 * Builds the transaction report rows for a date range. Mirrors the frontend
 * Transactions export: billiard sessions and POS sale items, each timestamp
 * rendered in 12-hour format. Returns a list of sheets, each
 * ['name' => ..., 'headers' => [...], 'rows' => [[...], ...]].
 */
function transactions_for_report(string $from, string $to, string $type): array
{
    $type = in_array($type, ['billiard', 'pos', 'all'], true) ? $type : 'all';
    $filter = "s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    $sheets = [];

    if ($type === 'all' || $type === 'billiard') {
        $rows = db_all("
            SELECT s.reference, s.total, s.discount, s.subtotal, s.billiard_amount, s.created_at,
                   COALESCE(u.full_name, '—') AS cashier,
                   COALESCE(t.table_number, '') AS table_number,
                   COALESCE(t.rate_per_hour, 0) AS rate_per_hour,
                   COALESCE(bs.customer_name, '') AS customer_name,
                   bs.start_time, bs.end_time,
                   COALESCE(bs.free_hour_used, 0) AS free_hour_used,
                   COALESCE(rs.downpayment, 0) AS downpayment
            FROM sales s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN billiard_sessions bs ON bs.id = s.billiard_session_id
            LEFT JOIN tables t ON t.id = bs.table_id
            LEFT JOIN reservations rs ON rs.session_id = bs.id
            WHERE $filter AND s.billiard_amount > 0
            ORDER BY s.id DESC
        ", $params);

        $data = [];
        foreach ($rows as $r) {
            $start = $r['start_time'] ?: '';
            $end = $r['end_time'] ?: '';
            $range = ($start !== '' && $end !== '')
                ? date('h:i A', strtotime($start)) . ' - ' . date('h:i A', strtotime($end))
                : '';
            $duration = '';
            if ($start !== '' && $end !== '') {
                $secs = max(0, (int)strtotime($end) - (int)strtotime($start));
                $duration = sprintf('%d:%02d:%02d', (int)floor($secs / 3600), (int)floor(($secs % 3600) / 60), $secs % 60);
            }
            // Discount breakdown label (loyalty vs promo), mirroring reports.php.
            $freeUsed = (int)$r['free_hour_used'];
            $loyalty = $freeUsed ? round(min((float)$r['discount'], (float)$r['rate_per_hour']), 2) : 0.0;
            $promo = max(0.0, round((float)$r['discount'] - $loyalty, 2));
            if ($loyalty > 0 && $promo > 0) $discountType = 'Loyalty + Promo';
            elseif ($loyalty > 0) $discountType = 'Loyalty';
            elseif ($promo > 0) $discountType = 'Promo';
            else $discountType = 'N/A';

            $data[] = [
                $r['reference'],
                ($r['table_number'] === '' || $r['table_number'] === '-') ? '' : $r['table_number'],
                $r['customer_name'],
                $range,
                $duration,
                $r['subtotal'],
                $discountType,
                (float)$r['downpayment'] > 0 ? $r['downpayment'] : '',
                $r['total'],
                format_12h($r['created_at']),
                $r['cashier'],
            ];
        }
        $sheets[] = [
            'name'    => 'Billiard',
            'headers' => ['Transaction ID', 'Table', 'Customer', 'Time Range', 'Duration', 'Subtotal', 'Discount', 'Downpayment', 'Grand Total', 'Transaction Date', 'Cashier'],
            'rows'    => $data,
        ];
    }

    if ($type === 'all' || $type === 'pos') {
        $items = db_all("
            SELECT s.reference, si.product_name, si.qty, si.selling_price, si.total,
                   COALESCE(p.buying_price, 0) AS unit_cost, s.created_at,
                   COALESCE(u.full_name, '—') AS cashier
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE $filter AND (s.billiard_amount IS NULL OR s.billiard_amount = 0)
            ORDER BY s.id DESC, si.id ASC
        ", $params);

        $data = [];
        foreach ($items as $i) {
            $data[] = [
                $i['reference'],
                $i['product_name'],
                $i['qty'],
                $i['selling_price'],
                $i['unit_cost'],
                $i['total'],
                round((float)$i['total'] - (float)$i['qty'] * (float)$i['unit_cost'], 2),
                format_12h($i['created_at']),
                $i['cashier'],
            ];
        }
        $sheets[] = [
            'name'    => 'POS',
            'headers' => ['Trans ID', 'Product Name', 'Qty', 'Selling Price', 'Buying Price', 'Subtotal', 'Line Profit', 'Transaction Date', 'Cashier'],
            'rows'    => $data,
        ];
    }

    return $sheets;
}

/**
 * Writes a minimal, dependency-free .xlsx workbook via ZipArchive.
 * $sheets: ['name' => sheet name, 'headers' => [...], 'rows' => [[...], ...]].
 * Numeric-looking values become real numbers; everything else is a string.
 */
function write_xlsx(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $count = count($sheets);
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_XML1, 'UTF-8');

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $count; $i++) {
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $sheetsXml = '';
    for ($i = 0; $i < $count; $i++) {
        $sheetsXml .= '<sheet name="' . $esc($sheets[$i]['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
    }
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetsXml . '</sheets></workbook>');

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $count; $i++) {
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');

    for ($i = 0; $i < $count; $i++) {
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_merge([$sheets[$i]['headers']], $sheets[$i]['rows']) as $row) {
            $sheetXml .= '<row>';
            foreach ($row as $v) {
                if ($v === null || $v === '') {
                    $sheetXml .= '<c/>';
                } elseif (is_numeric($v) && !preg_match('/^0\d/', (string)$v)) {
                    $sheetXml .= '<c><v>' . $esc((string)$v) . '</v></c>';
                } else {
                    $sheetXml .= '<c t="inlineStr"><is><t xml:space="preserve">' . $esc((string)$v) . '</t></is></c>';
                }
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheetXml);
    }

    $zip->close();
}

/**
 * Builds and emails the daily transaction report for the given date range.
 * Returns ['ok' => bool, 'message' => ...].
 */
function send_daily_transaction_report(string $to, string $from, string $toDate, string $type): array
{
    $name = get_setting('business_name', '');
    $sheets = transactions_for_report($from, $toDate, $type);

    $tmp = sys_get_temp_dir() . '/txn_report_' . bin2hex(random_bytes(6)) . '.xlsx';
    write_xlsx($tmp, $sheets);
    $content = base64_encode((string)file_get_contents($tmp));
    @unlink($tmp);

    $typeLabel = ['billiard' => 'Billiard', 'pos' => 'POS', 'all' => 'All'][$type] ?? 'All';
    $subject = ($name !== '' ? $name . ' — ' : '') . 'Transaction Report ' . $from . ' (' . $typeLabel . ')';
    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">'
        . '<h2 style="color:#166534;margin-bottom:8px">' . htmlspecialchars($name !== '' ? $name : 'Zoeys Billiard House') . '</h2>'
        . '<p>Here is the transaction report for <strong>' . htmlspecialchars($from) . '</strong>.</p>'
        . '<p style="color:#555">The Excel file with all ' . htmlspecialchars(strtolower($typeLabel)) . ' transactions is attached.</p>'
        . '<p style="color:#888;font-size:12px">Sent ' . date('Y-m-d h:i A') . '</p>'
        . '</div>';

    return send_resend_email($to, $subject, $html, [
        [
            'filename' => 'transactions-' . $from . '.xlsx',
            'content'  => $content,
            'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ]);
}

/**
 * Self-triggering "lazy cron": called on requests to check whether the daily
 * transaction report is due. Cheap when nothing is scheduled; the expensive
 * build+send happens at most once per day thanks to an atomic claim on the
 * email_report_last_sent setting (a concurrent request loses the claim).
 */
function maybe_send_daily_report(): void
{
    if (get_setting('email_report_enabled', '0') !== '1') return;
    $to = get_setting('email_report_recipient', '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $time = get_setting('email_report_time', '08:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '08:00';
    $today = date('Y-m-d');
    if (time() < strtotime($today . ' ' . $time)) return; // not due yet

    $db = db();
    // Ensure the key exists so the conditional UPDATE below can claim it.
    $db->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES ('email_report_last_sent', '')")->execute();
    $nowStr = date('Y-m-d H:i:s');
    $claim = $db->prepare("UPDATE settings SET svalue = ? WHERE skey = 'email_report_last_sent' AND (svalue = '' OR svalue < ?)");
    $claim->execute([$nowStr, $today . ' 00:00:00']);
    if ($claim->rowCount() === 0) return; // already sent today or another request won the claim

    $yesterday = date('Y-m-d', strtotime('yesterday'));
    $res = send_daily_transaction_report($to, $yesterday, $yesterday, get_setting('email_report_type', 'all'));

    if ($res['ok']) {
        audit_log('email_report', "Daily transaction report sent to {$to} — " . $res['message']);
    } else {
        // Roll the claim back so the next page load retries the send.
        $db->prepare("UPDATE settings SET svalue = '' WHERE skey = 'email_report_last_sent' AND svalue = ?")->execute([$nowStr]);
        audit_log('email_report', "Daily transaction report to {$to} FAILED — " . $res['message']);
    }
}