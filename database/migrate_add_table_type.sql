-- =====================================================
--  Migration: add table type (regular / vip / kubo)
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE tables
    ADD COLUMN type ENUM('regular','vip','kubo') NOT NULL DEFAULT 'regular'
    AFTER table_number;