<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_login();

$action = $_REQUEST['action'] ?? '';

$defaultSql = "SELECT p.*, c.name AS category, sup.name AS supplier
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN suppliers sup ON sup.id = p.supplier_id
               WHERE 1=1";

switch ($action) {

    case 'list': {
        $q = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $cat = (int)($_GET['category'] ?? 0);
        $params = [];
        $sql = $defaultSql;
        if ($q !== '') { $sql .= " AND p.name LIKE ?"; $params[] = like($q); }
        if (in_array($status, ['active', 'inactive'], true)) { $sql .= " AND p.status = ?"; $params[] = $status; }
        if ($cat > 0) { $sql .= " AND p.category_id = ?"; $params[] = $cat; }
        $sql .= " ORDER BY p.name";
        json_response(200, ['ok' => true, 'products' => db_all($sql, $params)]);
    }
    break;

    case 'export': {
        $q = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $cat = (int)($_GET['category'] ?? 0);
        $params = [];
        $sql = $defaultSql;
        if ($q !== '') { $sql .= " AND p.name LIKE ?"; $params[] = like($q); }
        if (in_array($status, ['active', 'inactive'], true)) { $sql .= " AND p.status = ?"; $params[] = $status; }
        if ($cat > 0) { $sql .= " AND p.category_id = ?"; $params[] = $cat; }
        $sql .= " ORDER BY p.name";

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, ['Name', 'Category', 'Supplier', 'Buying Price', 'Selling Price', 'Stock', 'Low Stock', 'Status']);
        foreach (db_all($sql, $params) as $r) {
            fputcsv($out, [$r['name'], $r['category'] ?? '', $r['supplier'] ?? '',
                           $r['buying_price'], $r['selling_price'], $r['stock'], $r['low_stock'], $r['status']]);
        }
        fclose($out);
        exit;
    }
    break;

    case 'import': {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(422, ['ok' => false, 'message' => 'No file uploaded.']);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            json_response(422, ['ok' => false, 'message' => 'File too large (max 2MB).']);
        }
        if (strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            json_response(422, ['ok' => false, 'message' => 'Please upload a .csv file.']);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) json_response(422, ['ok' => false, 'message' => 'Could not read the file.']);

        $admin = is_admin();
        $db = db();
        $db->beginTransaction();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        try {
            $line = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                $row = array_map(static fn($v) => trim((string)$v), $row);
                if ($line === 1) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
                    if (strtolower($row[0]) === 'name') continue; // header row
                }
                if (count(array_filter($row, static fn($v) => $v !== '')) === 0) continue;

                $name = $row[0] ?? '';
                if ($name === '') { $skipped++; continue; }
                $catName = $row[1] ?? '';
                $supName = $row[2] ?? '';
                $buying = (float)str_replace([',', ' '], '', $row[3] ?? 0);
                $selling = (float)str_replace([',', ' '], '', $row[4] ?? 0);
                $stock = (int)(float)str_replace([',', ' '], '', $row[5] ?? 0);
                $low = (int)(float)str_replace([',', ' '], '', $row[6] ?? 5);
                $status = strtolower($row[7] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                if ($buying < 0 || $selling < 0 || $stock < 0 || $low < 0) {
                    if (count($errors) < 5) $errors[] = "Line {$line}: negative value skipped.";
                    $skipped++;
                    continue;
                }

                $catId = null;
                if ($catName !== '') {
                    $c = db_row('SELECT id FROM categories WHERE name = ?', [$catName]);
                    if ($c) {
                        $catId = (int)$c['id'];
                    } else {
                        $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$catName]);
                        $catId = (int)$db->lastInsertId();
                    }
                }
                $supId = null;
                if ($supName !== '') {
                    $s = db_row('SELECT id FROM suppliers WHERE name = ?', [$supName]);
                    if ($s) {
                        $supId = (int)$s['id'];
                    } else {
                        $db->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$supName]);
                        $supId = (int)$db->lastInsertId();
                    }
                }

                $existing = db_row('SELECT * FROM products WHERE name = ?', [$name]);
                if ($existing) {
                    $oldStock = (int)$existing['stock'];
                    $newStock = $admin ? $stock : $oldStock;
                    $db->prepare("UPDATE products SET category_id=?, supplier_id=?, selling_price=?, buying_price=?,
                                  stock=?, low_stock=?, status=? WHERE id=?")
                        ->execute([$catId, $supId, $selling, $buying, $newStock, $low, $status, $existing['id']]);
                    if ($newStock !== $oldStock) {
                        $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                            ->execute([$existing['id'], $newStock - $oldStock, 'Import adjustment', (int)$_SESSION['user_id']]);
                    }
                    $updated++;
                } else {
                    $newStock = $admin ? $stock : 0;
                    $db->prepare("INSERT INTO products (name, category_id, supplier_id, selling_price, buying_price, stock, low_stock, status)
                                  VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$name, $catId, $supId, $selling, $buying, $newStock, $low, $status]);
                    $pid = (int)$db->lastInsertId();
                    if ($newStock > 0) {
                        $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                            ->execute([$pid, $newStock, 'Initial stock (import)', (int)$_SESSION['user_id']]);
                    }
                    $created++;
                }
            }
            fclose($handle);
            $db->commit();
            json_response(200, [
                'ok' => true,
                'message' => "Import finished: {$created} created, {$updated} updated, {$skipped} skipped.",
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ]);
        } catch (Throwable $ex) {
            fclose($handle);
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Import failed. No changes were saved.']);
        }
    }
    break;

    case 'save': {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cat = (int)($_POST['category_id'] ?? 0);
        $supplier = (int)($_POST['supplier_id'] ?? 0);
        $selling = (float)($_POST['selling_price'] ?? 0);
        $buying = (float)($_POST['buying_price'] ?? 0);
        $stockPosted = array_key_exists('stock', $_POST);
        $stock = (int)($_POST['stock'] ?? 0);
        $low = (int)($_POST['low_stock'] ?? 0);
        $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        if ($name === '') {
            json_response(422, ['ok' => false, 'message' => 'Product name required.']);
        }
        if ($selling < 0 || $buying < 0 || $stock < 0 || $low < 0) {
            json_response(422, ['ok' => false, 'message' => 'Values cannot be negative.']);
        }

        $db = db();
        $db->beginTransaction();
        try {
            if ($id > 0) {
                $oldStock = (int)db_value('SELECT stock FROM products WHERE id = ?', [$id]);
                if (!$stockPosted || !is_admin()) {
                    $stock = $oldStock; // keep current stock when not provided or for non-admins
                    $diff = 0;
                } else {
                    $diff = $stock - $oldStock;
                }
                $db->prepare("UPDATE products SET name=?, category_id=?, supplier_id=?, selling_price=?, buying_price=?, stock=?,
                              low_stock=?, status=? WHERE id=?")
                    ->execute([$name, $cat ?: null, $supplier ?: null, $selling, $buying, $stock, $low, $status, $id]);
                if ($diff !== 0) {
                    $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([$id, $diff, 'Inventory adjustment', (int)$_SESSION['user_id']]);
                }
            } else {
                if (!is_admin()) $stock = 0; // stock set by admin only
                $db->prepare("INSERT INTO products (name, category_id, supplier_id, selling_price, buying_price, stock, low_stock, status)
                              VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name, $cat ?: null, $supplier ?: null, $selling, $buying, $stock, $low, $status]);
                $pid = (int)$db->lastInsertId();
                if ($stock > 0) {
                    $db->prepare("INSERT INTO stock_logs (product_id, change_qty, reason, user_id) VALUES (?,?,?,?)")
                        ->execute([$pid, $stock, 'Initial stock', (int)$_SESSION['user_id']]);
                }
            }
            $db->commit();
            json_response(200, ['ok' => true, 'message' => 'Product saved.']);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Save failed.']);
        }
    }
    break;

    case 'delete': {
        if (!is_admin()) {
            json_response(403, ['ok' => false, 'message' => 'Admins only.']);
        }
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        json_response(200, ['ok' => true, 'message' => 'Product deleted.']);
    }
    break;

    case 'restock': {
        $id = (int)($_POST['id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Restock');
        $supplier = (int)($_POST['supplier_id'] ?? 0);
        if ($qty <= 0) json_response(422, ['ok' => false, 'message' => 'Quantity must be positive.']);
        $p = db_row('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p) json_response(404, ['ok' => false, 'message' => 'Product not found.']);
        if ($supplier > 0 && !db_value('SELECT id FROM suppliers WHERE id = ?', [$supplier])) {
            json_response(422, ['ok' => false, 'message' => 'Supplier not found.']);
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$qty, $id]);
            $db->prepare('INSERT INTO stock_logs (product_id, change_qty, reason, supplier_id, user_id) VALUES (?,?,?,?,?)')
                ->execute([$id, $qty, $reason, $supplier ?: null, (int)$_SESSION['user_id']]);
            $db->commit();
            json_response(200, ['ok' => true, 'message' => "Added {$qty} to stock."]);
        } catch (Throwable $ex) {
            $db->rollBack();
            json_response(500, ['ok' => false, 'message' => 'Restock failed.']);
        }
    }
    break;

    case 'categories': {
        $rows = db_all('SELECT * FROM categories ORDER BY name');
        $categories = [];
        foreach ($rows as $r) { $categories[] = $r; }
        json_response(200, ['ok' => true, 'categories' => $categories]);
    }
    break;

    case 'save_category': {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') json_response(422, ['ok' => false, 'message' => 'Category name required.']);
        // return existing or insert
        $existing = db_row('SELECT * FROM categories WHERE name = ?', [$name]);
        if ($existing) {
            json_response(200, ['ok' => true, 'id' => $existing['id'], 'name' => $existing['name']]);
        }
        db()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        json_response(200, ['ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $name]);
    }
    break;

    case 'suppliers': {
        $rows = db_all('SELECT * FROM suppliers ORDER BY name');
        $suppliers = [];
        foreach ($rows as $r) { $suppliers[] = $r; }
        json_response(200, ['ok' => true, 'suppliers' => $suppliers]);
    }
    break;

    case 'save_supplier': {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') json_response(422, ['ok' => false, 'message' => 'Supplier name required.']);
        // return existing or insert
        $existing = db_row('SELECT * FROM suppliers WHERE name = ?', [$name]);
        if ($existing) {
            json_response(200, ['ok' => true, 'id' => $existing['id'], 'name' => $existing['name']]);
        }
        db()->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$name]);
        json_response(200, ['ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $name]);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}