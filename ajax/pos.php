<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'products':
        $q = trim($_GET['q'] ?? '');
        $cat = (int)($_GET['category'] ?? 0);
        $params = [];
        $sql = "SELECT p.id, p.name, p.selling_price, p.stock, p.low_stock, c.name AS category
                FROM products p LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'active'";
        if ($q !== '') {
            $sql .= " AND p.name LIKE ?";
            $params[] = like($q);
        }
        if ($cat > 0) {
            $sql .= " AND p.category_id = ?";
            $params[] = $cat;
        }
        $sql .= " ORDER BY p.name";
        json_response(200, ['ok' => true, 'products' => db_all($sql, $params)]);
    break;

    case 'categories':
        json_response(200, ['ok' => true, 'categories' => db_all('SELECT id, name FROM categories ORDER BY name')]);
    break;

    case 'open_sessions':
        $rows = db_all("
            SELECT bs.id AS session_id, bs.start_time, bs.hours, t.id AS table_id,
                   t.table_number, t.rate_per_hour
            FROM billiard_sessions bs JOIN tables t ON t.id = bs.table_id
            WHERE bs.status = 'open'
            ORDER BY t.table_number
        ");
        // attach computed elapsed minutes for each open session
        foreach ($rows as &$r) {
            $mins = max(0, (int)floor((time() - strtotime($r['start_time'])) / 60));
            $r['elapsed_minutes'] = $mins;
        }
        unset($r);
        json_response(200, ['ok' => true, 'sessions' => $rows]);
    break;

    case 'checkout': {
        $body = json_decode(file_get_contents('php://input'), true);
        $items = $body['items'] ?? [];
        $method = in_array($body['payment_method'] ?? '', ['cash', 'gcash', 'card'], true)
            ? $body['payment_method'] : 'cash';
        $discount = (float)($body['discount'] ?? 0);
        $sessionId = (int)($body['billiard_session_id'] ?? 0);

        if (empty($items) && !$sessionId) {
            json_response(422, ['ok' => false, 'message' => 'Cart is empty.']);
        }

        $db = db();
        $db->beginTransaction();
        try {
            $lineItems = [];
            $subtotal = 0.0;
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty  = (int)($it['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;

                $p = db_row('SELECT * FROM products WHERE id = ? AND status = "active"', [$pid]);
                if (!$p) json_response(422, ['ok' => false, 'message' => 'Product not found.']);
                if ($p['stock'] < $qty) {
                    json_response(422, ['ok' => false, 'message' => "Insufficient stock for " . $p['name'] .
                        " (available: {$p['stock']})."]);
                }
                $lineTotal = $qty * (float)$p['selling_price'];
                $subtotal += $lineTotal;
                $lineItems[] = ['product_id' => $pid, 'name' => $p['name'], 'qty' => $qty,
                                'price' => (float)$p['selling_price'], 'total' => $lineTotal];
            }

            // billiard charge
            $billing = ['session_id' => null, 'hours' => null, 'amount' => 0.0];
            if ($sessionId > 0) {
                $s = db_row("
                    SELECT bs.*, t.table_number, t.rate_per_hour
                    FROM billiard_sessions bs
                    JOIN tables t ON t.id = bs.table_id
                    WHERE bs.id = ? AND bs.status = 'open'
                ", [$sessionId]);
                if (!$s) {
                    json_response(422, ['ok' => false, 'message' => 'Billiard session not found or already closed.']);
                }
                $minutes  = max(1, (int)ceil((time() - strtotime($s['start_time'])) / 60));
                $hours    = max(1, (int)ceil($minutes / 60), (int)round((float)$s['extended_hours']));
                if ((float)$s['prepaid'] > 0) {
                    // Already paid up front — skip the billiard charge.
                    $billing = [
                        'session_id' => $s['id'],
                        'hours' => $hours,
                        'amount' => 0.0,
                        'table_number' => $s['table_number'],
                        'prepaid' => (float)$s['prepaid'],
                    ];
                } else {
                    $amount   = $hours * (float)$s['rate_per_hour'];
                    $subtotal += $amount;
                    $billing = [
                        'session_id' => $s['id'],
                        'hours' => $hours,
                        'amount' => $amount,
                        'table_number' => $s['table_number'],
                        'prepaid' => 0.0,
                    ];
                }
            }

            if (empty($lineItems) && !$billing['session_id']) {
                json_response(422, ['ok' => false, 'message' => 'Cart is empty.']);
            }
            if ($billing['session_id'] && $billing['amount'] == 0 && empty($lineItems)) {
                json_response(422, ['ok' => false, 'message' => 'Billiard time was already paid at the counter.']);
            }

            $discount = max(0.0, min($discount, $subtotal));
            $total = $subtotal - $discount;

            // unique reference with retry
            $reference = make_reference();
            for ($i = 0; $i < 5; $i++) {
                if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
                $reference = make_reference();
            }

            $db->prepare("
                INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method,
                                   billiard_session_id, billiard_hours, billiard_amount)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $reference, (int)$_SESSION['user_id'], $subtotal, $discount, $total,
                $method, $billing['session_id'], $billing['hours'], $billing['amount'],
            ]);
            $saleId = (int)$db->lastInsertId();

            $saleItemStmt = $db->prepare(
                "INSERT INTO sale_items (sale_id, product_id, product_name, qty, selling_price, total) VALUES (?,?,?,?,?,?)"
            );
            $stockStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $logStmt = $db->prepare(
                "INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)"
            );
            foreach ($lineItems as $li) {
                $saleItemStmt->execute([$saleId, $li['product_id'], $li['name'], $li['qty'], $li['price'], $li['total']]);
                $stockStmt->execute([$li['qty'], $li['product_id']]);
                $logStmt->execute([$li['product_id'], -$li['qty'], 'Sale ' . $reference, (int)$_SESSION['user_id']]);
            }

            if ($billing['session_id']) {
                $closeAmount = $billing['amount'] > 0 ? $billing['amount'] : $billing['prepaid'];
                $db->prepare("UPDATE billiard_sessions SET end_time = ?, hours = ?, amount = ?, status = 'closed', user_id = ?
                              WHERE id = ?")
                    ->execute([now(), $billing['hours'], $closeAmount, (int)$_SESSION['user_id'], $billing['session_id']]);
                $db->prepare("UPDATE tables SET status = 'available' WHERE id = ?")
                    ->execute([(int)db_value('SELECT table_id FROM billiard_sessions WHERE id = ?', [$billing['session_id']])]);
            }

            $db->commit();

            $cashier = db_value('SELECT full_name FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);
            json_response(200, ['ok' => true, 'sale' => [
                'reference' => $reference,
                'datetime'  => date('Y-m-d h:i A'),
                'cashier'   => $cashier,
                'items'     => $lineItems,
                'billiard'  => $billing,
                'subtotal'  => round($subtotal, 2),
                'discount'  => round($discount, 2),
                'total'     => round($total, 2),
                'payment_method' => $method,
                'tendered'  => round((float)($body['tendered'] ?? 0), 2),
                'change'    => round((float)($body['tendered'] ?? 0) - $total, 2),
            ]]);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Checkout failed: ' . $ex->getMessage()]);
        }
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}