-- =====================================================
--  Add last_login tracking to users
--  Run in phpMyAdmin or: mysql -u root zoeys_billiard < migrate_users_last_login.sql
-- =====================================================

ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER created_at;
