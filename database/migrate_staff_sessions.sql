-- =====================================================
--  Migration: kubo table type + session customer/points
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE tables
    MODIFY type ENUM('regular','vip','ktv','kubo') NOT NULL DEFAULT 'regular';

ALTER TABLE billiard_sessions
    ADD COLUMN customer_name VARCHAR(100) NULL AFTER table_id,
    ADD COLUMN points TINYINT NOT NULL DEFAULT 0 AFTER extended_hours;