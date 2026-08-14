-- Loyalty stamps: one stamp per customer per calendar day (>=1 hour of play), 10 stamps = 1 free hour
-- NOTE: customers.loyalty_stamps column is added by the migration runner (guarded).
CREATE TABLE IF NOT EXISTS customer_stamps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  stamp_date DATE NOT NULL,
  awarded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_stamp_date (customer_id, stamp_date)
);