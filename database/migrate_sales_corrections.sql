-- =====================================================
--  Migration: sales correction tracking flags
--  added_missing = sale created via "Add Missing Session"
--  edited_at     = set when a completed sale is edited (billiard or POS)
--  Run in phpMyAdmin for existing databases.
-- =====================================================

ALTER TABLE sales
    ADD COLUMN added_missing TINYINT(1) NOT NULL DEFAULT 0 AFTER billiard_amount,
    ADD COLUMN edited_at DATETIME NULL AFTER added_missing;