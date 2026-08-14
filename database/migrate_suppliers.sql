-- =====================================================
--  Migration: suppliers table + supplier fields
--  Run in phpMyAdmin for existing databases.
-- =====================================================

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE products
    ADD COLUMN supplier_id INT NULL AFTER category_id;

ALTER TABLE stock_logs
    ADD COLUMN supplier_id INT NULL AFTER reason;

ALTER TABLE products
    ADD CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id) ON DELETE SET NULL;

ALTER TABLE stock_logs
    ADD CONSTRAINT fk_log_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id) ON DELETE SET NULL;