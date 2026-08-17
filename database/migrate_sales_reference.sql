-- Atomic sales-reference sequence (see includes/functions.php make_reference()).
-- This table MUST exist before checkout runs: make_reference() no longer
-- creates it at runtime (CREATE TABLE inside a transaction would implicitly
-- commit and break atomicity). Applied by:
-- source database/migrate_sales_reference.sql
CREATE TABLE IF NOT EXISTS seq_sales_reference (
    id TINYINT PRIMARY KEY,
    val BIGINT NOT NULL
) ENGINE=InnoDB;
