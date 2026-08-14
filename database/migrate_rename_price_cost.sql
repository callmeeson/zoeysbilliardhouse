-- =====================================================
--  Migration: rename price/cost -> selling_price/buying_price
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE products
    CHANGE price selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    CHANGE cost buying_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;

ALTER TABLE sale_items
    CHANGE price selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;