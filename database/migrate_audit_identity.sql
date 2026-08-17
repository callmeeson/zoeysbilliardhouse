-- Snapshot the actor's username + role on every audit_logs row so entries
-- stay attributable after the user is deleted (and admins cannot see deleted
-- superadmin actions via a NULL join). Applied by:
-- source database/migrate_audit_identity.sql
ALTER TABLE audit_logs
    ADD COLUMN user_name VARCHAR(100) NULL AFTER user_id,
    ADD COLUMN user_role VARCHAR(20) NULL AFTER user_name;

UPDATE audit_logs a
LEFT JOIN users u ON u.id = a.user_id
SET a.user_name = u.username,
    a.user_role = u.role;
