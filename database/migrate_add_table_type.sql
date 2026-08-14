-- =====================================================
--  Migration: add table type (regular / vip / ktv)
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE tables
    ADD COLUMN type ENUM('regular','vip','ktv') NOT NULL DEFAULT 'regular'
    AFTER table_number;