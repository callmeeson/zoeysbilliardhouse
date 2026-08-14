-- Migration: promo manager
-- Adds a promos table so the system can hold multiple named promos
-- (each with a discount percent, optional time window, and active flag).
-- Empty start/end means all-day. Windows may cross midnight (e.g. 22:00 -> 02:00).

CREATE TABLE IF NOT EXISTS promos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the previous single-promo behaviour (50% off, 08:00 - 12:00) so
-- existing deployments keep their promo out of the box.
INSERT INTO promos (name, discount_percent, start_time, end_time, is_active)
SELECT 'Happy Hour', 50.00, '08:00:00', '12:00:00', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM promos);
