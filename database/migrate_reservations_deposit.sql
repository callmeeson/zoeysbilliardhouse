-- Reservations: registered-customer support + downpayment
ALTER TABLE reservations
    ADD COLUMN customer_id INT NULL DEFAULT NULL AFTER customer_phone,
    ADD COLUMN is_walkin TINYINT(1) NOT NULL DEFAULT 1 AFTER customer_id,
    ADD COLUMN downpayment DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER notes;
