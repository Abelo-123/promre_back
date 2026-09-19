<?php

if (!defined('PRIMORE_PRIMARY_RESELLER_BALANCE_HELPER')) {
    define('PRIMORE_PRIMARY_RESELLER_BALANCE_HELPER', '1');

    function getPrimaryResellerEnvVar($name, $default = '')
    {
        if (function_exists('getEnvVar')) {
            return getEnvVar($name, $default);
        }

        $value = getenv($name);

        if ($value === false && isset($_ENV[$name])) {
            $value = $_ENV[$name];
        }

        if ($value === false && isset($_SERVER[$name])) {
            $value = $_SERVER[$name];
        }

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    function getPrimaryResellerBalanceKey()
    {
        $resellerId = trim((string)getPrimaryResellerEnvVar('RESELLER_ID', 'primore'));
        $resellerId = preg_replace('/[^a-z0-9_\-]/i', '_', $resellerId);

        if ($resellerId !== '') {
            return 'reseller_balance_' . $resellerId;
        }

        return 'reseller_balance_primore';
    }

    function normalizePrimaryResellerBalanceValue($value)
    {
        $value = trim((string)$value);

        if ($value === '' || !is_numeric($value)) {
            $value = '0';
        }

        return number_format((float)$value, 4, '.', '');
    }

    function fetchPrimaryResellerBalance($pdo, $forUpdate = false)
    {
        $key = getPrimaryResellerBalanceKey();

        $sql = "SELECT setting_value
                  FROM settings
                 WHERE setting_key = ?";

        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !isset($row['setting_value'])) {
            return 0.0;
        }

        return (float)normalizePrimaryResellerBalanceValue($row['setting_value']);
    }

    function updatePrimaryResellerBalance($pdo, $newBalance)
    {
        $key = getPrimaryResellerBalanceKey();
        $value = normalizePrimaryResellerBalanceValue($newBalance);

        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, created_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );

        $stmt->execute([$key, $value, $value]);

        require_once __DIR__ . '/paxyoo_notify.php';
        notifyPaxyooAdminBalanceChange($pdo);

        return (float)$value;
    }

    function auditPrimaryResellerBalanceChange($pdo, $oldBalance, $newBalance, $reason)
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO settings_audit (
                    setting_key,
                    old_value,
                    new_value,
                    change_reason,
                    created_at
                 )
                 VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                 )"
            );

            $stmt->execute([
                getPrimaryResellerBalanceKey(),
                normalizePrimaryResellerBalanceValue($oldBalance),
                normalizePrimaryResellerBalanceValue($newBalance),
                $reason
            ]);
        } catch (Exception $e) {
            // Ignore audit table absence if not present
        }
    }

    function adjustPrimaryResellerBalance($pdo, $delta, $reason)
    {
        $startedTransaction = false;

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $currentBalance = fetchPrimaryResellerBalance($pdo, true);
            $newBalance = max(0.00, (float)$currentBalance + (float)$delta);
            $newBalance = (float)normalizePrimaryResellerBalanceValue($newBalance);

            updatePrimaryResellerBalance($pdo, $newBalance);
            auditPrimaryResellerBalanceChange($pdo, $currentBalance, $newBalance, $reason);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return $newBalance;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
