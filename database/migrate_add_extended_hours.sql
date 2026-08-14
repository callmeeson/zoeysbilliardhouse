-- =====================================================
--  Migration: add extended_hours to billiard_sessions
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE billiard_sessions
    ADD COLUMN extended_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00
    AFTER amount;