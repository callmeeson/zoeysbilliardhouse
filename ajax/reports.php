<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
// Reports expose gross revenue, cost and profit — admin/superadmin only.
// No POS flow calls this file, so require_admin() is safe to gate everything.
require_admin();

$action = $_REQUEST['action'] ?? '';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$cashier = (int)($_GET['cashier'] ?? 0);
$shiftId = (int)($_GET['shift_id'] ?? 0);

function period_filter(string $from, string $to, int $cashier, int $shiftId): array
{
    $filter = "s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($cashier > 0) { $filter .= " AND s.user_id = ?"; $params[] = $cashier; }

    // Shift filter: match sales whose created_at time falls within the shift's window.
    if ($shiftId > 0) {
        $shift = db_row('SELECT * FROM shifts WHERE id = ?', [$shiftId]);
        if ($shift) {
            $start = $shift['start_time'];
            $end = $shift['end_time'];
            if ((int)$shift['next_day'] === 1) {
                // crosses midnight: from start onward OR before end
                $filter .= " AND (TIME(s.created_at) >= ? OR TIME(s.created_at) < ?)";
                $params[] = $start;
                $params[] = $end;
            } else {
                $filter .= " AND TIME(s.created_at) >= ? AND TIME(s.created_at) < ?";
                $params[] = $start;
                $params[] = $end;
            }
        }
    }
    return [$filter, $params];
}

[$filter, $params] = period_filter($from, $to, $cashier, $shiftId);

switch ($action) {

    case 'summary': {
        $gross = db_value("SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE $filter", $params);
        $billiard = db_value("SELECT COALESCE(SUM(s.billiard_amount),0) FROM sales s WHERE $filter", $params);
        $count = db_value("SELECT COUNT(*) FROM sales s WHERE $filter", $params);
        $cost = db_value("
            SELECT COALESCE(SUM(si.qty * p.buying_price),0)
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE $filter", $params);
        // billiard "cost" not tracked, assume 0
        $profit = ((float)$gross - (float)$cost);

        $byDay = db_all("
            SELECT DATE(created_at) AS d, COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
            FROM sales s WHERE $filter GROUP BY DATE(created_at) ORDER BY d
        ", $params);

        $byPayment = db_all("
            SELECT payment_method, COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
            FROM sales s WHERE $filter GROUP BY payment_method
        ", $params);

        $byProduct = db_all("
            SELECT si.product_name, SUM(si.qty) AS qty,
                   COALESCE(SUM(si.total),0) AS revenue,
                   COALESCE(SUM(si.qty * p.buying_price),0) AS cost
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE $filter
            GROUP BY si.product_name ORDER BY revenue DESC LIMIT 10
        ", $params);

        // previous period of the same length, immediately before $from
        $days = max(1, (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevFrom = date('Y-m-d', strtotime($from . " -{$days} days"));
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        [$prevFilter, $prevParams] = period_filter($prevFrom, $prevTo, $cashier, $shiftId);
        $prevGross = db_value("SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE $prevFilter", $prevParams);
        $prevCount = db_value("SELECT COUNT(*) FROM sales s WHERE $prevFilter", $prevParams);
        $prevCost = db_value("
            SELECT COALESCE(SUM(si.qty * p.buying_price),0)
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE $prevFilter", $prevParams);

        // last 12 months trend (respects cashier/shift filters).
        // Anchored to the first of the month so day-of-month drift (e.g. a
        // month-end date like Aug 31 rolling to Sep 30) can never shift or
        // drop a month from the chart.
        $trendAnchor = date('Y-m-01');
        $trendFrom = date('Y-m-01', strtotime($trendAnchor . ' -11 months'));
        [$trendFilter, $trendParams] = period_filter($trendFrom, date('Y-m-d'), $cashier, $shiftId);
        $monthly = db_all("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COALESCE(SUM(total),0) AS total, COUNT(*) AS cnt
            FROM sales s WHERE $trendFilter GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY m
        ", $trendParams);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $key = date('Y-m', strtotime($trendFrom . " +{$i} months"));
            $months[$key] = ['m' => $key, 'total' => 0, 'cnt' => 0];
        }
        foreach ($monthly as $r) {
            if (isset($months[$r['m']])) { $months[$r['m']] = $r; }
        }

        json_response(200, [
            'ok' => true,
            'gross' => round((float)$gross, 2),
            'product_revenue' => round((float)$gross - (float)$billiard, 2),
            'billiard' => round((float)$billiard, 2),
            'cost' => round((float)$cost, 2),
            'profit' => round($profit, 2),
            'count' => (int)$count,
            'by_day' => $byDay,
            'by_payment' => $byPayment,
            'by_product' => $byProduct,
            'prev_gross' => round((float)$prevGross, 2),
            'prev_profit' => round((float)$prevGross - (float)$prevCost, 2),
            'prev_count' => (int)$prevCount,
            'monthly' => array_values($months),
        ]);
    }
    break;

    case 'products': {
        $rows = db_all("
            SELECT p.id, p.name, sup.name AS supplier, p.buying_price, p.selling_price,
                   p.stock, p.status,
                   COALESCE(agg.units_sold, 0) AS units_sold,
                   COALESCE(agg.revenue, 0) AS revenue,
                   COALESCE(agg.cost, 0) AS cost
            FROM products p
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            LEFT JOIN (
                SELECT si.product_id, SUM(si.qty) AS units_sold, SUM(si.total) AS revenue,
                       SUM(si.qty * p2.buying_price) AS cost
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN products p2 ON p2.id = si.product_id
                WHERE $filter
                GROUP BY si.product_id
            ) agg ON agg.product_id = p.id
            ORDER BY revenue DESC, p.name
        ", $params);

        $totalValue = db_value('SELECT COALESCE(SUM(stock * buying_price),0) FROM products');
        $totalStock = (int)db_value('SELECT COALESCE(SUM(stock),0) FROM products');

        json_response(200, [
            'ok' => true,
            'products' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'supplier' => $r['supplier'] ?? '',
                'buying_price' => round((float)$r['buying_price'], 2),
                'selling_price' => round((float)$r['selling_price'], 2),
                'stock' => (int)$r['stock'],
                'status' => $r['status'],
                'units_sold' => (int)$r['units_sold'],
                'revenue' => round((float)$r['revenue'], 2),
                'cost' => round((float)$r['cost'], 2),
                'profit' => round((float)$r['revenue'] - (float)$r['cost'], 2),
            ], $rows),
            'inventory_value' => round((float)$totalValue, 2),
            'total_stock' => $totalStock,
        ]);
    }
    break;

    case 'inventory': {
        $valuation = db_all("
            SELECT p.name, sup.name AS supplier, p.stock, p.buying_price,
                   ROUND(p.stock * p.buying_price, 2) AS stock_value, p.status
            FROM products p
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            ORDER BY stock_value DESC, p.name
        ");

        $suppliers = db_all("
            SELECT COALESCE(sup.name, 'Unassigned') AS supplier, COUNT(*) AS drops,
                   SUM(sl.change_qty) AS qty, MAX(sl.created_at) AS last_restock
            FROM stock_logs sl
            LEFT JOIN suppliers sup ON sup.id = sl.supplier_id
            WHERE sl.change_qty > 0 AND DATE(sl.created_at) BETWEEN ? AND ?
            GROUP BY COALESCE(sup.name, 'Unassigned')
            ORDER BY qty DESC
        ", [$from, $to]);

        $logs = db_all("
            SELECT sl.change_qty, sl.reason, sl.created_at,
                   p.name AS product, COALESCE(sup.name, '') AS supplier,
                   COALESCE(u.full_name, '') AS cashier
            FROM stock_logs sl
            LEFT JOIN products p ON p.id = sl.product_id
            LEFT JOIN suppliers sup ON sup.id = sl.supplier_id
            LEFT JOIN users u ON u.id = sl.user_id
            WHERE DATE(sl.created_at) BETWEEN ? AND ?
            ORDER BY sl.id DESC
            LIMIT 500
        ", [$from, $to]);

        $totalValue = 0.0;
        foreach ($valuation as $v) { $totalValue += (float)$v['stock_value']; }

        json_response(200, [
            'ok' => true,
            'valuation' => $valuation,
            'suppliers' => $suppliers,
            'logs' => $logs,
            'total_value' => round($totalValue, 2),
        ]);
    }
    break;

    case 'transactions': {        $type = $_GET['type'] ?? 'all';
        $typeFilter = '';
        if ($type === 'billiard') $typeFilter = " AND s.billiard_amount > 0";
        elseif ($type === 'pos') $typeFilter = " AND (s.billiard_amount IS NULL OR s.billiard_amount = 0)";
        $txnFilter = $filter . $typeFilter;
        $txnParams = $params;
        // Server-side pagination so the response stays bounded as the
        // dataset grows (frontend passes page/page_size; default 500, cap 1000).
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(1000, max(1, (int)($_GET['page_size'] ?? 500)));
        $offset = ($page - 1) * $pageSize;
        $total = (int)db_value("SELECT COUNT(*) FROM sales s WHERE $txnFilter", $txnParams);
        $rows = db_all("
            SELECT s.id, s.reference, s.total, s.discount, s.subtotal, s.payment_method,
                   s.user_id,
                   s.billiard_session_id, s.billiard_hours, s.billiard_amount, s.added_missing, s.edited_at, s.status, s.created_at,
                   COALESCE(u.full_name, '—') AS cashier,
                   COALESCE(t.table_number, '') AS table_number,
                   COALESCE(t.rate_per_hour, 0) AS rate_per_hour,
                   COALESCE(bs.customer_name, '') AS customer_name,
                   bs.start_time, bs.end_time,
                   bs.hours AS session_hours,
                   COALESCE(bs.free_hour_used, 0) AS free_hour_used,
                   COALESCE(bs.status, '') AS session_status,
                   COALESCE(rs.downpayment, 0) AS downpayment
            FROM sales s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN billiard_sessions bs ON bs.id = s.billiard_session_id
            LEFT JOIN tables t ON t.id = bs.table_id
            LEFT JOIN reservations rs ON rs.session_id = bs.id
            WHERE $txnFilter
            ORDER BY s.id DESC
            LIMIT ? OFFSET ?
        ", array_merge($txnParams, [$pageSize, $offset]));
        // Fetch all line items for the page in one query (was N+1 per row).
        $itemsBySale = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (db_all("
                SELECT si.sale_id, si.product_id, si.product_name, si.qty, si.selling_price, si.total,
                       COALESCE(p.buying_price, 0) AS unit_cost
                FROM sale_items si
                LEFT JOIN products p ON p.id = si.product_id
                WHERE si.sale_id IN ($in)
                ORDER BY si.sale_id, si.id ASC
            ", $ids) as $item) {
                $saleId = (int)$item['sale_id'];
                unset($item['sale_id']);
                $itemsBySale[$saleId][] = $item;
            }
        }
        foreach ($rows as &$r) {
            $items = $itemsBySale[(int)$r['id']] ?? [];
            foreach ($items as &$i) {
                $i['profit'] = round((float)$i['total'] - (float)$i['qty'] * (float)$i['unit_cost'], 2);
            }
            unset($i);
            $r['items'] = $items;
            $r['item_count'] = array_sum(array_map(static fn($i) => $i['qty'], $items));
            $r['duration'] = null;
            if ($r['start_time'] && $r['end_time']) {
                $secs = max(0, (int)strtotime($r['end_time']) - (int)strtotime($r['start_time']));
                $r['duration'] = sprintf('%d:%02d:%02d', (int)floor($secs / 3600), (int)floor(($secs % 3600) / 60), $secs % 60);
            }
            // Billiard rows: the stored subtotal/discount were recorded at
            // billing time. The old code rebuilt them from the table's
            // CURRENT rate, which rewrote history whenever the rate changed.
            // Only the discount breakdown label is derived.
            if (!empty($r['billiard_amount'])) {
                $freeUsed = (int)($r['free_hour_used'] ?? 0);
                $loyalty = $freeUsed ? round(min((float)$r['discount'], (float)$r['rate_per_hour']), 2) : 0.0;
                $promo = max(0.0, round((float)$r['discount'] - $loyalty, 2));
                if ($loyalty > 0 && $promo > 0) $r['discount_type'] = 'Loyalty + Promo';
                elseif ($loyalty > 0) $r['discount_type'] = 'Loyalty';
                elseif ($promo > 0) $r['discount_type'] = 'Promo';
                else $r['discount_type'] = 'N/A';
            } else {
                $r['discount_type'] = 'N/A';
            }
        }
        unset($r);
        json_response(200, ['ok' => true, 'transactions' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
    }
    break;

    case 'void': {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $sale = db_row('SELECT id, reference, status FROM sales WHERE id = ?', [$id]);
        if (!$sale) json_response(404, ['ok' => false, 'message' => 'Sale not found.']);
        if ($sale['status'] === 'void') json_response(422, ['ok' => false, 'message' => 'Already voided.']);

        $db = db();
        $db->beginTransaction();
        try {
            db()->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$id]);
            // restore stock
            foreach (db_all('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$id]) as $item) {
                if ($item['product_id']) {
                    db()->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')
                        ->execute([$item['qty'], $item['product_id']]);
                    db()->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([$item['product_id'], $item['qty'], "Void {$sale['reference']}", (int)$_SESSION['user_id']]);
                }
            }
            $db->commit();
            audit_log('sale_void', "Voided sale {$sale['reference']} (#{$id})");
            json_response(200, ['ok' => true, 'message' => 'Sale voided. Stock restored.']);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Void failed.']);
        }
    }
    break;

    case 'cashiers': {
        json_response(200, ['ok' => true, 'users' => db_all("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")]);
    }
    break;

    case 'shifts': {
        $rows = db_all("SELECT id, name, start_time, end_time, next_day FROM shifts WHERE is_active = 1 ORDER BY start_time, id");
        json_response(200, ['ok' => true, 'shifts' => $rows]);
    }
    break;

    // Dead time per table: idle minutes inside each day's shift window.
    // Dead time = shift window length - union of playing intervals (clipped to
    // the window), so before-first and after-last idle count too. A specific
    // shift_id limits the window; shift_id = 0 uses the union of all active
    // shifts (falls back to the full day when none are configured).
    case 'table_dead_time': {
        if (!strtotime($from) || !strtotime($to) || strtotime($to) < strtotime($from)) {
            json_response(422, ['ok' => false, 'message' => 'Invalid date range.']);
        }
        $shift = null;
        if ($shiftId > 0) {
            $shift = db_row('SELECT * FROM shifts WHERE id = ? AND is_active = 1', [$shiftId]);
            if (!$shift) json_response(404, ['ok' => false, 'message' => 'Shift not found.']);
        }
        $activeShifts = $shift ? [$shift] : db_all("SELECT * FROM shifts WHERE is_active = 1 ORDER BY start_time, id");
        if (!$activeShifts) {
            $activeShifts = [['name' => 'Full Day', 'start_time' => '00:00:00', 'end_time' => '24:00:00', 'next_day' => 0]];
        }
        $shiftName = $shift ? $shift['name'] : 'All Shifts';

        // One operating window per day: from the earliest shift start to the
        // latest shift end across the active shifts. A shift whose end time is
        // before its start time crosses midnight and is extended by a day.
        $windows = [];
        $dayEnd = strtotime($to . ' 23:59:59');
        for ($ts = strtotime($from . ' 00:00:00'); $ts <= $dayEnd; $ts += 86400) {
            $wStart = null;
            $wEnd = 0;
            foreach ($activeShifts as $s) {
                [$sh, $sm] = array_map('intval', explode(':', $s['start_time']));
                [$eh, $em] = array_map('intval', explode(':', $s['end_time']));
                $sStart = $ts + $sh * 3600 + $sm * 60;
                $sEnd = $ts + $eh * 3600 + $em * 60;
                if ($sEnd <= $sStart) $sEnd += 86400; // crosses midnight
                if ($wStart === null || $sStart < $wStart) $wStart = $sStart;
                if ($sEnd > $wEnd) $wEnd = $sEnd;
            }
            $windows[] = ['day' => date('Y-m-d', $ts), 'start' => $wStart, 'end' => $wEnd];
        }

        $tables = db_all("SELECT id, table_number, type FROM tables ORDER BY table_number");
        $byTable = [];
        foreach (db_all("
            SELECT table_id, start_time, end_time FROM billiard_sessions
            WHERE status IN ('open','closed') AND end_time IS NOT NULL
              AND start_time < ? AND end_time > ?
            ORDER BY table_id, start_time
        ", [date('Y-m-d H:i:s', strtotime($to . ' +2 days')), date('Y-m-d H:i:s', strtotime($from . ' -1 day'))]) as $r) {
            $byTable[(int)$r['table_id']][] = ['start' => strtotime($r['start_time']), 'end' => strtotime($r['end_time'])];
        }

        $summary = [];
        $days = [];
        foreach ($tables as $t) {
            $tid = (int)$t['id'];
            $sess = $byTable[$tid] ?? [];
            $agg = ['window_hours' => 0.0, 'play_hours' => 0.0, 'day_count' => 0];
            foreach ($windows as $w) {
                // Clip sessions to the window, then merge overlaps into busy intervals.
                $ints = [];
                foreach ($sess as $s) {
                    $a = max($s['start'], $w['start']);
                    $b = min($s['end'], $w['end']);
                    if ($a < $b) $ints[] = [$a, $b];
                }
                $merged = [];
                if ($ints) {
                    usort($ints, static fn($x, $y) => $x[0] <=> $y[0]);
                    $curS = $ints[0][0];
                    $curE = $ints[0][1];
                    foreach (array_slice($ints, 1) as $iv) {
                        if ($iv[0] <= $curE) { $curE = max($curE, $iv[1]); }
                        else { $merged[] = [$curS, $curE]; $curS = $iv[0]; $curE = $iv[1]; }
                    }
                    $merged[] = [$curS, $curE];
                }
                // Dead windows = complement of busy intervals: shift start -> first
                // play, gaps between plays, last play -> shift end.
                $deadWindows = [];
                $cursor = $w['start'];
                foreach ($merged as $iv) {
                    if ($iv[0] > $cursor) $deadWindows[] = [$cursor, $iv[0]];
                    if ($iv[1] > $cursor) $cursor = $iv[1];
                }
                if ($cursor < $w['end']) $deadWindows[] = [$cursor, $w['end']];

                $playSecs = 0;
                foreach ($merged as $iv) { $playSecs += $iv[1] - $iv[0]; }
                $winSecs = $w['end'] - $w['start'];
                if ($winSecs <= 0) continue;

                $winH = $winSecs / 3600;
                $playH = $playSecs / 3600;
                $agg['window_hours'] += $winH;
                $agg['play_hours'] += $playH;
                $agg['day_count']++;

                $dayKey = $w['day'];
                if (!isset($days[$dayKey])) {
                    $days[$dayKey] = ['date' => $dayKey, 'shift_name' => $shiftName, 'tables' => []];
                }
                $days[$dayKey]['tables'][] = [
                    'table_id' => $tid,
                    'table_number' => $t['table_number'],
                    'type' => $t['type'],
                    'window_hours' => round($winH, 2),
                    'play_hours' => round($playH, 2),
                    'dead_hours' => round($winH - $playH, 2),
                    'utilization' => round($playSecs / $winSecs * 100, 1),
                    'dead_windows' => array_map(static fn($iv) => [
                        'start' => date('Y-m-d H:i:s', $iv[0]),
                        'end' => date('Y-m-d H:i:s', $iv[1]),
                    ], $deadWindows),
                ];
            }
            $winTot = $agg['window_hours'];
            $playTot = $agg['play_hours'];
            $summary[] = [
                'id' => $tid,
                'table_number' => $t['table_number'],
                'type' => $t['type'],
                'days' => $agg['day_count'],
                'window_hours' => round($winTot, 2),
                'play_hours' => round($playTot, 2),
                'dead_hours' => round($winTot - $playTot, 2),
                'utilization' => $winTot > 0 ? round($playTot / $winTot * 100, 1) : 0,
            ];
        }

        json_response(200, [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'shift_name' => $shiftName,
            'summary' => $summary,
            'days' => array_values($days),
        ]);
    }
    break;

    // Admin corrections. Staff mistakes (wrong hours recorded, forgotten
    // sessions/extensions) are fixed here rather than by voiding.

    // Rebuild a completed sale from corrected inputs.
    // billiard: start_time, end_time, payment_method (hours & totals recomputed).
    // pos: items (JSON array of {product_id, qty}), payment_method (stock reconciled).
    case 'update_sale': {
        $id = (int)($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $sale = db_row('SELECT * FROM sales WHERE id = ?', [$id]);
        if (!$sale) json_response(404, ['ok' => false, 'message' => 'Sale not found.']);
        if ($sale['status'] !== 'completed') json_response(422, ['ok' => false, 'message' => 'Only completed transactions can be edited.']);

        if ($type === 'billiard') {
            $session = db_row('SELECT * FROM billiard_sessions WHERE id = ?', [$sale['billiard_session_id']]);
            if (!$session) json_response(422, ['ok' => false, 'message' => 'Billiard session not found.']);
            $table = db_row('SELECT table_number, rate_per_hour FROM tables WHERE id = ?', [$session['table_id']]);
            $rate = (float)($table['rate_per_hour'] ?? 0);
            $start = trim($_POST['start_time'] ?? '');
            $end = trim($_POST['end_time'] ?? '');
            $method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['payment_method'] : 'cash';
            if (!strtotime($start) || !strtotime($end) || strtotime($end) <= strtotime($start)) {
                json_response(422, ['ok' => false, 'message' => 'End time must be after start time.']);
            }
            $hours = round((strtotime($end) - strtotime($start)) / 3600, 2);
            if ($hours < 0.5 || $hours > 48) json_response(422, ['ok' => false, 'message' => 'Session must be between 30 minutes and 48 hours.']);
            if (abs(round($hours * 2) - $hours * 2) > 0.0001) {
                json_response(422, ['ok' => false, 'message' => 'Session time must be in 30-minute increments (e.g. 0.5, 1, 1.5, 2 hours) — pick an end time that is a half or full hour from the start.']);
            }

            $subtotal = round($hours * $rate, 2);
            $discount = round(min((float)$sale['discount'], $subtotal), 2);
            $total = round($subtotal - $discount, 2);

            $db = db();
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE sales SET subtotal = ?, discount = ?, total = ?, payment_method = ?, billiard_hours = ?, billiard_amount = ?, edited_at = NOW() WHERE id = ?")
                    ->execute([$subtotal, $discount, $total, $method, $hours, $total, $id]);
                $db->prepare("UPDATE billiard_sessions SET start_time = ?, end_time = ?, hours = ?, amount = ? WHERE id = ?")
                    ->execute([date('Y-m-d H:i:s', strtotime($start)), date('Y-m-d H:i:s', strtotime($end)), $hours, $total, $session['id']]);
                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                json_response(500, ['ok' => false, 'message' => 'Failed to update sale.']);
            }
            audit_log('sale_edit', "Edited billiard sale {$sale['reference']} (#{$id}) on '{$table['table_number']}' — now {$hours} hrs, " . money($total));
            json_response(200, ['ok' => true, 'message' => 'Transaction updated.', 'hours' => $hours, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total]);
        }

        if ($type !== 'pos') json_response(422, ['ok' => false, 'message' => 'Unknown transaction type.']);
        if (!empty($sale['billiard_session_id'])) json_response(422, ['ok' => false, 'message' => 'This is a billiard transaction.']);

        $raw = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($raw)) json_response(422, ['ok' => false, 'message' => 'Invalid items.']);
        $method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['payment_method'] : 'cash';

        // Staff who rang the sale and the billing date/time so it lands on the
        // correct day/shift report.
        $newCreated = $sale['created_at'];
        $billing = trim($_POST['billing_time'] ?? '');
        if ($billing !== '') {
            if (!strtotime($billing)) json_response(422, ['ok' => false, 'message' => 'Invalid billing time.']);
            if (strtotime($billing) > time() + 300) json_response(422, ['ok' => false, 'message' => 'Billing time cannot be in the future.']);
            $newCreated = date('Y-m-d H:i:s', strtotime($billing));
        }
        $newUser = (int)$sale['user_id'];
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $u = db_row('SELECT id FROM users WHERE id = ? AND is_active = 1', [$uid]);
            if (!$u) json_response(422, ['ok' => false, 'message' => 'Staff must be an active user.']);
            $newUser = (int)$u['id'];
        }

        $newMap = [];
        foreach ($raw as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['qty'] ?? 0);
            if ($pid > 0 && $qty > 0) $newMap[$pid] = ($newMap[$pid] ?? 0) + $qty;
        }
        if (!$newMap) json_response(422, ['ok' => false, 'message' => 'Sale must keep at least one item.']);

        $db = db();
        $db->beginTransaction();
        try {
            // Validate every product once, then reuse the rows.
            $products = [];
            foreach (array_keys($newMap) as $pid) {
                $p = db_row("SELECT id, name, selling_price, stock FROM products WHERE id = ? AND status = 'active'", [$pid]);
                if (!$p) throw new RuntimeException('A product no longer exists or is inactive.');
                $products[$pid] = $p;
            }

            $oldMap = [];
            foreach (db_all('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$id]) as $oi) {
                if ($oi['product_id']) $oldMap[(int)$oi['product_id']] = (int)$oi['qty'];
            }

            // Stock reconcile: positive delta restores, negative deducts.
            foreach (array_unique(array_merge(array_keys($oldMap), array_keys($newMap))) as $pid) {
                $delta = ($oldMap[$pid] ?? 0) - ($newMap[$pid] ?? 0);
                if ($delta === 0) continue;
                if ($delta > 0) {
                    $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $pid]);
                    $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([$pid, $delta, "Adjust {$sale['reference']}", (int)$_SESSION['user_id']]);
                } else {
                    $need = -$delta;
                    $upd = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                    $upd->execute([$need, $pid, $need]);
                    if ($upd->rowCount() !== 1) throw new RuntimeException('Insufficient stock for ' . $products[$pid]['name'] . '.');
                    $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([$pid, -$need, "Adjust {$sale['reference']}", (int)$_SESSION['user_id']]);
                }
            }

            // Rebuild line items at current selling price.
            $db->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$id]);
            $itemStmt = $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, selling_price, total) VALUES (?,?,?,?,?,?)");
            $subtotal = 0.0;
            foreach ($newMap as $pid => $qty) {
                $p = $products[$pid];
                $lineTotal = round($qty * (float)$p['selling_price'], 2);
                $subtotal += $lineTotal;
                $itemStmt->execute([$id, $pid, $p['name'], $qty, (float)$p['selling_price'], $lineTotal]);
            }
            $discount = round(min((float)$sale['discount'], $subtotal), 2);
            $total = round($subtotal - $discount, 2);
            $db->prepare("UPDATE sales SET subtotal = ?, discount = ?, total = ?, payment_method = ?, edited_at = NOW(), created_at = ?, user_id = ? WHERE id = ?")
                ->execute([$subtotal, $discount, $total, $method, $newCreated, $newUser, $id]);
            $db->commit();
        } catch (RuntimeException $ex) {
            $db->rollBack();
            json_response(422, ['ok' => false, 'message' => $ex->getMessage()]);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to update sale.']);
        }
        audit_log('sale_edit', "Edited POS sale {$sale['reference']} (#{$id}) — subtotal " . money($subtotal));
        json_response(200, ['ok' => true, 'message' => 'Transaction updated.', 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total]);
    }
    break;

    // Record a game that staff forgot to start: creates a closed session +
    // sale for a past time, billed at the table's current rate.
    case 'add_missing_session': {
        $tableId = (int)($_POST['table_id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $hours = (float)($_POST['hours'] ?? 0);
        $method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['payment_method'] : 'cash';

        // Staff who handled the game (defaults to the current admin) and the
        // billing date/time so the sale lands in the correct day/shift report.
        $userId = (int)($_POST['user_id'] ?? 0);
        $staff = null;
        if ($userId > 0) {
            $staff = db_row('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1', [$userId]);
        }
        if (!$staff) {
            $staff = db_row('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1', [(int)$_SESSION['user_id']]);
        }
        if (!$staff) json_response(422, ['ok' => false, 'message' => 'Staff must be an active user.']);

        $table = db_row('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$table) json_response(404, ['ok' => false, 'message' => 'Table not found.']);
        if (!strtotime($start)) json_response(422, ['ok' => false, 'message' => 'Invalid start time.']);
        if (strtotime($start) > time() + 300) json_response(422, ['ok' => false, 'message' => 'Start time cannot be in the future.']);
        if ($hours < 0.5 || $hours > 48) json_response(422, ['ok' => false, 'message' => 'Session must be between 30 minutes and 48 hours.']);
        if (abs(round($hours * 2) - $hours * 2) > 0.0001) {
            json_response(422, ['ok' => false, 'message' => 'Session time must be in 30-minute increments (e.g. 0.5, 1, 1.5, 2 hours).']);
        }

        $startTs = strtotime($start);

        $billing = trim($_POST['billing_time'] ?? '');
        $billingTs = ($billing !== '' && strtotime($billing)) ? strtotime($billing) : time();
        if ($billingTs > time() + 300) json_response(422, ['ok' => false, 'message' => 'Billing time cannot be in the future.']);
        if ($billingTs < $startTs) json_response(422, ['ok' => false, 'message' => 'Billing time must be at or after the game start time.']);

        $name = null;
        if ($customerId > 0) {
            $c = db_row('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
            if (!$c) json_response(404, ['ok' => false, 'message' => 'Customer not found.']);
            $name = $c['name'];
        } else {
            $name = $customerName !== '' ? $customerName : null;
            if ($name === null) json_response(422, ['ok' => false, 'message' => 'Enter a walk-in name or select a customer.']);
        }

        $rate = (float)$table['rate_per_hour'];
        $subtotal = round($hours * $rate, 2);
        $discount = 0.0;
        $total = $subtotal;
        $startTs = strtotime($start);
        $end = date('Y-m-d H:i:s', $startTs + (int)round($hours * 3600));

        // Informational overlap check (does not block — admins may be fixing
        // back-to-back games).
        $overlap = (int)db_value("SELECT COUNT(*) FROM billiard_sessions
                                  WHERE table_id = ? AND status IN ('open','closed')
                                    AND start_time < ? AND end_time > ?",
                                  [$tableId, $end, date('Y-m-d H:i:s', $startTs)]);

        $db = db();
        $db->beginTransaction();
        try {
            $recordedAt = date('Y-m-d H:i:s', $billingTs);
            $db->prepare("INSERT INTO billiard_sessions (table_id, customer_id, customer_name, start_time, end_time, hours, amount, prepaid, extended_hours, status, user_id, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?, 'closed', ?, ?)")
                ->execute([$tableId, $customerId > 0 ? $customerId : null, $name, date('Y-m-d H:i:s', $startTs), $end, $hours, $total, $total, $hours, $staff['id'], $recordedAt]);
            $sid = (int)$db->lastInsertId();
            $reference = make_reference();
            for ($i = 0; $i < 5; $i++) {
                if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
                $reference = make_reference();
            }
            $db->prepare("INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method, billiard_session_id, billiard_hours, billiard_amount, added_missing, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,1,?)")
                ->execute([$reference, $staff['id'], $subtotal, $discount, $total, $method, $sid, $hours, $total, $recordedAt]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to add session.']);
        }
        audit_log('session_add', "Added missing session on '{$table['table_number']}' for " . ($name ?? 'walk-in') . " — {$hours} hrs, " . money($total) . ", billed by {$staff['full_name']}");
        json_response(200, ['ok' => true, 'message' => 'Session added. Billed ' . money($total) . '.', 'overlap' => $overlap, 'reference' => $reference, 'session_id' => $sid]);
    }
    break;

    // Add forgotten extension time to an already-closed session and bill it,
    // merged into the session's single transaction (one sale per session).
    case 'extend_closed_session': {
        $sid = (int)($_POST['session_id'] ?? 0);
        $hours = (float)($_POST['hours'] ?? 0);
        $method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['payment_method'] : 'cash';
        $s = db_row('SELECT * FROM billiard_sessions WHERE id = ? AND status = "closed"', [$sid]);
        if (!$s) json_response(404, ['ok' => false, 'message' => 'Session not found or not closed.']);
        if ($hours < 0.5 || $hours > 48) json_response(422, ['ok' => false, 'message' => 'Add between 30 minutes and 48 hours.']);
        if (abs(round($hours * 2) - $hours * 2) > 0.0001) {
            json_response(422, ['ok' => false, 'message' => 'Extension must be in 30-minute increments (e.g. 0.5, 1, 1.5, 2 hours).']);
        }
        $table = db_row('SELECT table_number, rate_per_hour FROM tables WHERE id = ?', [$s['table_id']]);
        $rate = (float)$table['rate_per_hour'];
        $amount = round($hours * $rate, 2);
        $sale = db_row('SELECT * FROM sales WHERE billiard_session_id = ? AND status = "completed"', [$sid]);
        if (!$sale) json_response(422, ['ok' => false, 'message' => 'No completed sale found for this session.']);

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE billiard_sessions SET end_time = DATE_ADD(end_time, INTERVAL ? MINUTE),
                          hours = hours + ?, extended_hours = extended_hours + ?, amount = amount + ?, prepaid = prepaid + ?
                          WHERE id = ?")
                ->execute([(int)round($hours * 60), $hours, $hours, $amount, $amount, $sid]);
            $db->prepare("UPDATE sales SET subtotal = subtotal + ?, total = total + ?, billiard_hours = billiard_hours + ?,
                          billiard_amount = billiard_amount + ?, payment_method = ? WHERE id = ?")
                ->execute([$amount, $amount, $hours, $amount, $method, $sale['id']]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to extend session.']);
        }
        $txt = rtrim(rtrim(sprintf('%.2f', $hours), '0'), '.');
        audit_log('session_extend', "Extended closed session #{$sid} on '{$table['table_number']}' by {$txt} hrs — added " . money($amount));
        json_response(200, ['ok' => true, 'message' => 'Session extended by ' . $txt . ' hr(s). Added ' . money($amount) . '.', 'amount' => $amount, 'new_end' => date('Y-m-d H:i:s', strtotime($s['end_time']) + (int)round($hours * 3600))]);
    }
    break;

    // Record a POS sale that staff forgot to ring up: rebuilds line items at
    // current selling price, deducts stock, and backdates the billing time.
    case 'add_missing_pos_sale': {
        $raw = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($raw)) json_response(422, ['ok' => false, 'message' => 'Invalid items.']);
        $method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'card'], true) ? $_POST['payment_method'] : 'cash';
        $discount = round(max(0.0, (float)($_POST['discount'] ?? 0)), 2);

        $newMap = [];
        foreach ($raw as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['qty'] ?? 0);
            if ($pid > 0 && $qty > 0) $newMap[$pid] = ($newMap[$pid] ?? 0) + $qty;
        }
        if (!$newMap) json_response(422, ['ok' => false, 'message' => 'Sale must include at least one item.']);

        // Staff who rang the sale (defaults to the current admin).
        $userId = (int)($_POST['user_id'] ?? 0);
        $staff = null;
        if ($userId > 0) $staff = db_row('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if (!$staff) $staff = db_row('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1', [(int)$_SESSION['user_id']]);
        if (!$staff) json_response(422, ['ok' => false, 'message' => 'Staff must be an active user.']);

        // Billing date/time so the sale lands in the correct day/shift report.
        $billing = trim($_POST['billing_time'] ?? '');
        $billingTs = ($billing !== '' && strtotime($billing)) ? strtotime($billing) : time();
        if ($billingTs > time() + 300) json_response(422, ['ok' => false, 'message' => 'Billing time cannot be in the future.']);

        $db = db();
        $db->beginTransaction();
        try {
            $products = [];
            foreach (array_keys($newMap) as $pid) {
                $p = db_row("SELECT id, name, selling_price, stock FROM products WHERE id = ? AND status = 'active'", [$pid]);
                if (!$p) throw new RuntimeException('A product no longer exists or is inactive.');
                $products[$pid] = $p;
            }

            $reference = make_reference();
            for ($i = 0; $i < 5; $i++) {
                if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
                $reference = make_reference();
            }

            $recordedAt = date('Y-m-d H:i:s', $billingTs);
            $db->prepare("INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method, added_missing, created_at)
                          VALUES (?,?,?,?,?,?,1,?)")
                ->execute([$reference, $staff['id'], 0, 0, 0, $method, $recordedAt]);
            $saleId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, selling_price, total) VALUES (?,?,?,?,?,?)");
            $stockStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $logStmt = $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)");
            $subtotal = 0.0;
            foreach ($newMap as $pid => $qty) {
                $p = $products[$pid];
                if ($qty > (int)$p['stock']) throw new RuntimeException('Insufficient stock for ' . $p['name'] . ' (available: ' . $p['stock'] . ').');
                $lineTotal = round($qty * (float)$p['selling_price'], 2);
                $subtotal += $lineTotal;
                $itemStmt->execute([$saleId, $pid, $p['name'], $qty, (float)$p['selling_price'], $lineTotal]);
                $stockStmt->execute([$qty, $pid, $qty]);
                if ($stockStmt->rowCount() !== 1) throw new RuntimeException('Insufficient stock for ' . $p['name'] . '.');
                $logStmt->execute([$pid, -$qty, 'Missing ' . $reference, $staff['id']]);
            }
            $discount = round(max(0.0, min($discount, $subtotal)), 2);
            $total = round($subtotal - $discount, 2);
            $db->prepare("UPDATE sales SET subtotal = ?, discount = ?, total = ? WHERE id = ?")
                ->execute([$subtotal, $discount, $total, $saleId]);
            $db->commit();
        } catch (RuntimeException $ex) {
            $db->rollBack();
            json_response(422, ['ok' => false, 'message' => $ex->getMessage()]);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to add sale.']);
        }
        audit_log('sale_add', "Added missing POS sale {$reference} — subtotal " . money($subtotal) . ", billed by {$staff['full_name']}");
        json_response(200, ['ok' => true, 'message' => 'Sale added. Billed ' . money($total) . '.', 'reference' => $reference, 'sale_id' => $saleId, 'subtotal' => $subtotal, 'total' => $total]);
    }
    break;

    // Permanently remove a completed transaction. POS sales get their stock
    // returned; billiard sales remove the session (and any linked reservation).
    case 'delete_sale': {
        $id = (int)($_POST['id'] ?? 0);
        $sale = db_row('SELECT * FROM sales WHERE id = ?', [$id]);
        if (!$sale) json_response(404, ['ok' => false, 'message' => 'Sale not found.']);
        if ($sale['status'] !== 'completed') json_response(422, ['ok' => false, 'message' => 'Only completed transactions can be deleted.']);

        $db = db();
        $db->beginTransaction();
        try {
            if (!empty($sale['billiard_session_id'])) {
                $db->prepare('DELETE FROM reservations WHERE session_id = ?')->execute([$sale['billiard_session_id']]);
                $db->prepare('DELETE FROM billiard_sessions WHERE id = ?')->execute([$sale['billiard_session_id']]);
            } else {
                foreach (db_all('SELECT product_id, qty FROM sale_items WHERE sale_id = ? AND product_id IS NOT NULL', [$id]) as $it) {
                    $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([(int)$it['qty'], (int)$it['product_id']]);
                    $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([(int)$it['product_id'], (int)$it['qty'], 'Delete ' . $sale['reference'], (int)$_SESSION['user_id']]);
                }
            }
            $db->prepare('DELETE FROM sales WHERE id = ?')->execute([$id]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Failed to delete sale.']);
        }
        audit_log('sale_delete', "Deleted {$sale['reference']} (#{$id}) — " . money((float)$sale['total']));
        json_response(200, ['ok' => true, 'message' => 'Transaction deleted.']);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}