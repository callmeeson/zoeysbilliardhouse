-- Login throttling (DB-backed so clearing cookies cannot bypass the lockout).
-- Two limits: per IP+username (5 per 15 min) and per IP (20 per 15 min) so
-- password-spraying many usernames from one address is also limited.
-- Applied by: source database/migrate_login_attempts.sql
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (ip, username, attempted_at)
) ENGINE=InnoDB;
