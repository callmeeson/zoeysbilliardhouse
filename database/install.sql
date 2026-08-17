-- =====================================================
--  Zoeys Billiard House Management System
--  Database: zoeys_billiard
--  Run this in phpMyAdmin or: mysql -u root < install.sql
-- =====================================================

DROP DATABASE IF EXISTS zoeys_billiard;
CREATE DATABASE zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zoeys_billiard;

-- -----------------------------------------------------
-- users
-- -----------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','staff','superadmin') NOT NULL DEFAULT 'staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- categories (products)
-- -----------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- suppliers (product suppliers)
-- -----------------------------------------------------
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- products (store inventory)
-- -----------------------------------------------------
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category_id INT NULL,
    supplier_id INT NULL,
    selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    buying_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    low_stock INT NOT NULL DEFAULT 5,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- tables (billiard tables)
-- -----------------------------------------------------
CREATE TABLE tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20) NOT NULL UNIQUE,
    type ENUM('regular','vip','ktv','kubo') NOT NULL DEFAULT 'regular',
    rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('available','occupied','maintenance') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- customers (registered members)
-- -----------------------------------------------------
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    membership ENUM('regular','silver','gold','platinum') NOT NULL DEFAULT 'regular',
    points INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    loyalty_stamps INT NOT NULL DEFAULT 0,
    loyalty_completed INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- billiard_sessions (open/closed table runs)
-- -----------------------------------------------------
CREATE TABLE billiard_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_id INT NOT NULL,
    customer_id INT NULL,
    customer_name VARCHAR(100) NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    hours DECIMAL(5,2) NULL,
    amount DECIMAL(10,2) NULL,
    prepaid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    extended_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    points TINYINT NOT NULL DEFAULT 0,
    status ENUM('open','closed','void') NOT NULL DEFAULT 'open',
    void_reason VARCHAR(500) NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    free_hour_used TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_sesh_table FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    CONSTRAINT fk_sesh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sesh_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- reservations
-- -----------------------------------------------------
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_id INT NULL,
    is_walkin TINYINT(1) NOT NULL DEFAULT 1,
    table_id INT NOT NULL,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    notes TEXT NULL,
    downpayment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    session_id INT NULL,
    status ENUM('pending','confirmed','cancelled','completed','playing','no_show','rescheduled') NOT NULL DEFAULT 'confirmed',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_table FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
    CONSTRAINT fk_res_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- sales (transactions)
-- -----------------------------------------------------
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,
    user_id INT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','gcash','card') NOT NULL DEFAULT 'cash',
    billiard_session_id INT NULL,
    billiard_hours DECIMAL(5,2) NULL,
    billiard_amount DECIMAL(10,2) NULL,
    status ENUM('completed','void') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_session FOREIGN KEY (billiard_session_id) REFERENCES billiard_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- sale_items
-- -----------------------------------------------------
CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(100) NOT NULL,
    qty INT NOT NULL,
    selling_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- stock_logs (restock / adjustments / sales deductions)
-- -----------------------------------------------------
CREATE TABLE stock_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    change_qty INT NOT NULL,
    reason VARCHAR(100) NOT NULL,
    supplier_id INT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_log_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- settings (system key/value store)
-- -----------------------------------------------------
CREATE TABLE settings (
    skey VARCHAR(50) PRIMARY KEY,
    svalue TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- shifts (work hours grouping for reports)
-- -----------------------------------------------------
CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    next_day TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = end time is on the following day',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- promos (time-window discounts)
-- -----------------------------------------------------
CREATE TABLE promos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- audit_logs (activity trail)
-- -----------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    user_name VARCHAR(100) NULL,
    user_role VARCHAR(20) NULL,
    action VARCHAR(50) NOT NULL,
    detail TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- customer_stamps (loyalty stamps, one per customer per day)
-- -----------------------------------------------------
CREATE TABLE customer_stamps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    stamp_date DATE NOT NULL,
    awarded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_stamp_date (customer_id, stamp_date)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- login_attempts (brute-force throttle: per IP+username and per IP)
-- -----------------------------------------------------
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (ip, username, attempted_at)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- seq_sales_reference (atomic sale reference counter)
-- -----------------------------------------------------
CREATE TABLE seq_sales_reference (
    id TINYINT PRIMARY KEY,
    val BIGINT NOT NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Seed data
-- -----------------------------------------------------
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$4LnFPC49yWO4aqDK44lwpug..dueckU5saVTMWAPnvkoy18GVoqkm', 'Administrator', 'admin'),
('staff', '$2y$10$4LnFPC49yWO4aqDK44lwpug..dueckU5saVTMWAPnvkoy18GVoqkm', 'Front Desk Staff', 'staff'),
('superadmin', '$2y$10$67mLYZg4hXO6KBf/Xfu50O1G/XpTsZ3n9rJsATjDwEstXm06mUSza', 'Super Administrator', 'superadmin');

-- IMPORTANT: admin/staff use password 'admin123', superadmin uses 'SuperAdmin@123'.
-- Hashes above are bcrypt.

INSERT INTO categories (name) VALUES
('Food'),
('Beverages'),
('Snacks'),
('Cigarettes');

INSERT INTO customers (name, phone, membership, points) VALUES
('Eson Estanislao', '0917-555-0101', 'gold',     8),
('Maria Santos',    '0918-555-0102', 'silver',   4),
('Juan Dela Cruz',  '0919-555-0103', 'regular',  1),
('Ana Reyes',       '0920-555-0104', 'platinum', 10);

INSERT INTO products (name, category_id, selling_price, buying_price, stock, low_stock) VALUES
('Carbonara Pasta',   1, 150.00, 90.00,  30, 5),
('Burger',            1,  95.00, 55.00,  40, 5),
('Crispy Pata',       1, 320.00, 200.00, 15, 3),
('Coca-Cola (Can)',   2,  45.00, 30.00, 120, 10),
('Red Horse (Bottle)', 2,  70.00, 48.00,  96, 10),
('San Miguel Pale',    2,  75.00, 52.00,  96, 10),
('Iced Tea',           2,  35.00, 18.00,  80, 10),
('Water Bottle',       2,  20.00, 10.00,  100, 10),
('Potato Chips',       3,  30.00, 20.00,  60, 10),
('Peanuts',            3,  25.00, 15.00,  60, 10),
('Marlboro (Pack)',    4, 130.00, 118.00, 50, 10),
('Mighty (Pack)',      4, 118.00, 107.00, 50, 10);

INSERT INTO tables (table_number, type, rate_per_hour, status) VALUES
('Table 1', 'regular', 120.00, 'available'),
('Table 2', 'regular', 120.00, 'available'),
('Table 3', 'vip',     150.00, 'available'),
('Table 4', 'vip',     150.00, 'available'),
('Table 5', 'ktv',     200.00, 'available'),
('Table 6', 'vip',     200.00, 'maintenance');

INSERT INTO settings (skey, svalue) VALUES
('business_name', 'Zoeys Billiard House'),
('business_address', ''),
('business_phone', ''),
('promo_start', '08:00'),
('promo_end', '12:00'),
('promo_label', '50% Promo');

INSERT INTO shifts (name, start_time, end_time, next_day) VALUES
('Morning', '08:00:00', '17:30:00', 0),
('Night',   '17:30:00', '02:30:00', 1);

INSERT INTO promos (name, discount_percent, start_time, end_time, is_active) VALUES
('Happy Hour', 50.00, '08:00:00', '12:00:00', 1);