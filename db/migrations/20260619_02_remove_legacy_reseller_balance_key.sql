START TRANSACTION;

INSERT INTO settings_audit (
  setting_key,
  old_value,
  new_value,
  change_reason
)
SELECT
  'reseller_balance_primore',
  setting_value,
  NULL,
  'delete_legacy_reseller_balance_primore'
FROM settings
WHERE setting_key = 'reseller_balance_primore';

DELETE FROM settings
WHERE setting_key = 'reseller_balance_primore';

COMMIT;
