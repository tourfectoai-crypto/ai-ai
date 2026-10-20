-- 2026_10_18_000001_upgrade_smtp_deliverability.sql
ALTER TABLE `email_smtp_settings`
ADD COLUMN `daily_limit` INT UNSIGNED DEFAULT 500 AFTER `is_active`,
ADD COLUMN `sent_today` INT UNSIGNED DEFAULT 0 AFTER `daily_limit`,
ADD COLUMN `warmup_enabled` TINYINT(1) DEFAULT 0 AFTER `sent_today`,
ADD COLUMN `warmup_step_increment` INT UNSIGNED DEFAULT 50 AFTER `warmup_enabled`,
ADD COLUMN `spf_verified` TINYINT(1) DEFAULT 0,
ADD COLUMN `dkim_verified` TINYINT(1) DEFAULT 0,
ADD COLUMN `dmarc_verified` TINYINT(1) DEFAULT 0,
ADD COLUMN `health_score` TINYINT UNSIGNED DEFAULT 100,
ADD COLUMN `last_used_at` TIMESTAMP NULL DEFAULT NULL;