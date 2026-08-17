-- Loyalty stamps: one stamp per customer per calendar day (>=1 hour of play), 10 stamps = 1 free hour
CREATE TABLE IF NOT EXISTS customer_stamps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  stamp_date DATE NOT NULL,
  awarded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_stamp_date (customer_id, stamp_date)
);

-- Guarded column adds (MySQL 8 has no "ADD COLUMN IF NOT EXISTS"):
SET @has_loyalty_stamps := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'loyalty_stamps');
SET @sql := IF(@has_loyalty_stamps = 0,
    'ALTER TABLE customers ADD COLUMN loyalty_stamps INT NOT NULL DEFAULT 0 AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_loyalty_completed := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'loyalty_completed');
SET @sql := IF(@has_loyalty_completed = 0,
    'ALTER TABLE customers ADD COLUMN loyalty_completed INT NOT NULL DEFAULT 0 AFTER loyalty_stamps',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_free_hour_used := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billiard_sessions' AND column_name = 'free_hour_used');
SET @sql := IF(@has_free_hour_used = 0,
    'ALTER TABLE billiard_sessions ADD COLUMN free_hour_used TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_void_reason := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'billiard_sessions' AND column_name = 'void_reason');
SET @sql := IF(@has_void_reason = 0,
    'ALTER TABLE billiard_sessions ADD COLUMN void_reason VARCHAR(500) NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;