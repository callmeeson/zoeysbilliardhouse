-- =====================================================
--  Shifts migration
--  Run in phpMyAdmin or: mysql -u root zoeys_billiard < migrate_shifts.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    next_day TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = end time is on the following day',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed the two default shifts:
-- Morning: 8:00 AM – 5:30 PM
-- Night:   5:30 PM – 2:30 AM (next day)
INSERT INTO shifts (name, start_time, end_time, next_day) VALUES
('Morning', '08:00:00', '17:30:00', 0),
('Night',   '17:30:00', '02:30:00', 1)
ON DUPLICATE KEY UPDATE name = name;
