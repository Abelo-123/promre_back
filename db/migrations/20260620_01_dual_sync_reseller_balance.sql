CREATE TABLE IF NOT EXISTS settings_backup_20260620 AS
SELECT setting_key, setting_value, created_at
FROM settings
WHERE setting_key IN (
  'reseller_balance',
  'reseller_balance_primore',
  'total_deposit',
  'min_rate_multiplier',
  'rate_multiplier'
);

CREATE TABLE IF NOT EXISTS settings_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(255) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  change_reason VARCHAR(191) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_settings_audit_key_created (setting_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (setting_key, setting_value, created_at)
VALUES ('reseller_balance_primore', '0.0000', NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO settings (setting_key, setting_value, created_at)
VALUES ('reseller_balance', '0.0000', NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;

UPDATE settings
SET setting_value = '0'
WHERE setting_key IN (
  'reseller_balance',
  'reseller_balance_primore'
)
AND TRIM(setting_value) NOT REGEXP '^-?[0-9]+([.][0-9]+)?$';

START TRANSACTION;

SET @primo = (
  SELECT CAST(setting_value AS DECIMAL(15,4))
  FROM settings
  WHERE setting_key = 'reseller_balance_primore'
  LIMIT 1
);

SET @canon = (
  SELECT CAST(setting_value AS DECIMAL(15,4))
  FROM settings
  WHERE setting_key = 'reseller_balance'
  LIMIT 1
);

SET @target = GREATEST(COALESCE(@primo, 0), COALESCE(@canon, 0));

INSERT INTO settings_audit (
  setting_key,
  old_value,
  new_value,
  change_reason
)
SELECT
  setting_key,
  setting_value,
  CAST(@target AS CHAR),
  'one_time_dual_sync'
FROM settings
WHERE setting_key IN (
  'reseller_balance',
  'reseller_balance_primore'
)
AND CAST(setting_value AS DECIMAL(15,4)) <> @target;

UPDATE settings
SET setting_value = CAST(@target AS CHAR)
WHERE setting_key IN (
  'reseller_balance',
  'reseller_balance_primore'
)
AND CAST(setting_value AS DECIMAL(15,4)) <> @target;

COMMIT;
