START TRANSACTION;

INSERT INTO settings_audit (
  setting_key,
  old_value,
  new_value,
  change_reason
)
SELECT
  'reseller_balance',
  setting_value,
  NULL,
  'delete_generic_reseller_balance'
FROM settings
WHERE setting_key = 'reseller_balance';

DELETE FROM settings
WHERE setting_key = 'reseller_balance';

COMMIT;
