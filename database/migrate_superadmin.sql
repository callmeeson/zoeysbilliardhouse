-- =====================================================
--  Superadmin + System Settings + Audit Logs migration
--  Run in phpMyAdmin or: mysql -u root zoeys_billiard < migrate_superadmin.sql
-- =====================================================

-- 1) Extend role enum to include superadmin
ALTER TABLE users MODIFY COLUMN role ENUM('admin','staff','superadmin') NOT NULL DEFAULT 'staff';

-- 2) Create a default superadmin account (password: SuperAdmin@123)
--    Hash below is bcrypt for "SuperAdmin@123".
INSERT INTO users (username, password, full_name, role, is_active)
VALUES ('superadmin', '$2y$10$67mLYZg4hXO6KBf/Xfu50O1G/XpTsZ3n9rJsATjDwEstXm06mUSza', 'Super Administrator', 'superadmin', 1)
ON DUPLICATE KEY UPDATE role = 'superadmin';

-- 3) System settings (key/value)
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(50) PRIMARY KEY,
    svalue TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (skey, svalue) VALUES
('business_name', 'Zoeys Billiard House'),
('business_address', ''),
('business_phone', ''),
('promo_start', '08:00'),
('promo_end', '12:00'),
('promo_label', '50% Promo')
ON DUPLICATE KEY UPDATE skey = skey;

-- 4) Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    detail TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
