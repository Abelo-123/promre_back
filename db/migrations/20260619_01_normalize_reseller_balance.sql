CREATE TABLE IF NOT EXISTS settings_backup_20260619 AS
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
VALUES ('reseller_balance', '0.00', NOW())
ON DUPLICATE KEY UPDATE setting_value = setting_value;

UPDATE settings
SET setting_value = CAST(
  CAST(
    COALESCE(NULLIF(TRIM(setting_value), ''), '0')
    AS DECIMAL(15,4)
  )
  AS CHAR
)
WHERE setting_key IN (
  'reseller_balance',
  'reseller_balance_primore'
);

START TRANSACTION;

INSERT INTO settings_audit (
  setting_key,
  old_value,
  new_value,
  change_reason
)
SELECT
  'reseller_balance',
  canonical.setting_value,
  legacy.setting_value,
  'normalize_legacy_reseller_balance_primore'
FROM settings AS canonical
JOIN settings AS legacy
  ON legacy.setting_key = 'reseller_balance_primore'
WHERE canonical.setting_key = 'reseller_balance'
  AND CAST(legacy.setting_value AS DECIMAL(15,4)) > CAST(canonical.setting_value AS DECIMAL(15,4));

UPDATE settings AS canonical
JOIN settings AS legacy
  ON legacy.setting_key = 'reseller_balance_primore'
SET canonical.setting_value = legacy.setting_value
WHERE canonical.setting_key = 'reseller_balance'
  AND CAST(legacy.setting_value AS DECIMAL(15,4)) > CAST(canonical.setting_value AS DECIMAL(15,4));

COMMIT;
