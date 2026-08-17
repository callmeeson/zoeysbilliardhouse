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
                   s.billiard_hours, s.billiard_amount, s.status, s.created_at,
                   COALESCE(u.full_name, '—') AS cashier,
                   COALESCE(t.table_number, '') AS table_number,
                   COALESCE(t.rate_per_hour, 0) AS rate_per_hour,
                   COALESCE(bs.customer_name, '') AS customer_name,
                   bs.start_time, bs.end_time,
                   bs.hours AS session_hours,
                   COALESCE(bs.free_hour_used, 0) AS free_hour_used,
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
                SELECT si.sale_id, si.product_name, si.qty, si.selling_price, si.total,
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

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}