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

    case 'checkout': {
        $body = json_decode(file_get_contents('php://input'), true);
        $items = $body['items'] ?? [];
        $method = in_array($body['payment_method'] ?? '', ['cash', 'gcash', 'card'], true)
            ? $body['payment_method'] : 'cash';
        $discount = (float)($body['discount'] ?? 0);
        if ($discount > 0 && !is_admin()) {
            json_response(403, ['ok' => false, 'message' => 'Only admins can apply manual discounts.']);
        }

        if (empty($items)) {
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
                if (!$p) throw new RuntimeException('Product not found.');
                if ($p['stock'] < $qty) {
                    throw new RuntimeException("Insufficient stock for " . $p['name'] .
                        " (available: {$p['stock']}).");
                }
                $lineTotal = round($qty * (float)$p['selling_price'], 2);
                $subtotal += $lineTotal;
                $lineItems[] = ['product_id' => $pid, 'name' => $p['name'], 'qty' => $qty,
                                'price' => (float)$p['selling_price'], 'total' => $lineTotal];
            }

            $discount = round(max(0.0, min($discount, $subtotal)), 2);
            $total = round($subtotal - $discount, 2);

            // Never accept a cash payment short of the total.
            if ($method === 'cash' && (float)($body['tendered'] ?? 0) < $total - 0.001) {
                throw new RuntimeException('Tendered amount is less than the total of ' . money($total) . '.');
            }

            // unique reference with retry
            $reference = make_reference();
            for ($i = 0; $i < 5; $i++) {
                if (!db_value('SELECT 1 FROM sales WHERE reference = ?', [$reference])) break;
                $reference = make_reference();
            }

            $db->prepare("
                INSERT INTO sales (reference, user_id, subtotal, discount, total, payment_method)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $reference, (int)$_SESSION['user_id'], $subtotal, $discount, $total, $method,
            ]);
            $saleId = (int)$db->lastInsertId();

            $saleItemStmt = $db->prepare(
                "INSERT INTO sale_items (sale_id, product_id, product_name, qty, selling_price, total) VALUES (?,?,?,?,?,?)"
            );
            $stockStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $logStmt = $db->prepare(
                "INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)"
            );
            foreach ($lineItems as $li) {
                $saleItemStmt->execute([$saleId, $li['product_id'], $li['name'], $li['qty'], $li['price'], $li['total']]);
                $stockStmt->execute([$li['qty'], $li['product_id'], $li['qty']]);
                if ($stockStmt->rowCount() !== 1) {
                    // Guarded decrement: catches stock that ran out between the
                    // check above and this update (concurrent checkouts).
                    throw new RuntimeException('Insufficient stock for ' . $li['name'] . ' (sold out just now).');
                }
                $logStmt->execute([$li['product_id'], -$li['qty'], 'Sale ' . $reference, (int)$_SESSION['user_id']]);
            }

            $db->commit();

            $cashier = db_value('SELECT full_name FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);
            json_response(200, ['ok' => true, 'sale' => [
                'reference' => $reference,
                'datetime'  => date('Y-m-d h:i A'),
                'cashier'   => $cashier,
                'items'     => $lineItems,
                'subtotal'  => round($subtotal, 2),
                'discount'  => round($discount, 2),
                'total'     => round($total, 2),
                'payment_method' => $method,
                'tendered'  => round((float)($body['tendered'] ?? 0), 2),
                'change'    => round((float)($body['tendered'] ?? 0) - $total, 2),
            ]]);
        } catch (RuntimeException $ex) {
            $db->rollBack();
            json_response(422, ['ok' => false, 'message' => $ex->getMessage()]);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Checkout failed: ' . $ex->getMessage()]);
        }
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}