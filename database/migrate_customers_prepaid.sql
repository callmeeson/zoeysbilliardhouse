-- =====================================================
--  Migration: customers + prepaid session billing
--  Run in phpMyAdmin for existing databases.
-- =====================================================

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    membership ENUM('regular','silver','gold','platinum') NOT NULL DEFAULT 'regular',
    points INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE billiard_sessions
    ADD COLUMN customer_id INT NULL AFTER table_id,
    ADD COLUMN prepaid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount;

ALTER TABLE billiard_sessions
    ADD CONSTRAINT fk_sesh_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL;

INSERT INTO customers (name, phone, membership, points) VALUES
('Eson Estanislao', '0917-555-0101', 'gold',     8),
('Maria Santos',    '0918-555-0102', 'silver',   4),
('Juan Dela Cruz',  '0919-555-0103', 'regular',  1),
('Ana Reyes',       '0920-555-0104', 'platinum', 10);