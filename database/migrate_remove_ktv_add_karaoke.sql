-- Convert legacy KTV rooms to VIP tables before removing the KTV type.
UPDATE tables SET type = 'vip' WHERE type = 'ktv';

ALTER TABLE tables
    MODIFY type ENUM('regular','vip','kubo') NOT NULL DEFAULT 'regular';

ALTER TABLE billiard_sessions
    ADD COLUMN karaoke TINYINT(1) NOT NULL DEFAULT 0 AFTER free_hour_used;