<?php

if (!defined('PRIMORE_RESELLER_BALANCE_HELPER')) {
    define('PRIMORE_RESELLER_BALANCE_HELPER', '1');

    function getResellerEnvVar($name, $default = '')
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

    function getResellerBalanceKeys()
    {
        $resellerId = trim((string)getResellerEnvVar('RESELLER_ID', 'primore'));
        $resellerId = preg_replace('/[^a-z0-9_\-]/i', '_', $resellerId);

        $keys = ['reseller_balance'];

        if ($resellerId !== '') {
            array_unshift($keys, 'reseller_balance_' . $resellerId);
        }

        return array_values(array_unique($keys));
    }

    function normalizeResellerBalanceValue($value)
    {
        $value = trim((string)$value);

        if ($value === '' || !is_numeric($value)) {
            $value = '0';
        }

        return number_format((float)$value, 4, '.', '');
    }

    function fetchResellerBalanceRows($pdo, $forUpdate = false)
    {
        $keys = getResellerBalanceKeys();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $sql = "SELECT setting_key, setting_value
                  FROM settings
                 WHERE setting_key IN ({$placeholders})";

        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($keys);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    function fetchResellerBalance($pdo, $forUpdate = false)
    {
        $keys = getResellerBalanceKeys();
        $rows = fetchResellerBalanceRows($pdo, $forUpdate);

        foreach ($keys as $key) {
            if (isset($rows[$key]) && is_numeric(trim((string)$rows[$key]))) {
                return (float)trim((string)$rows[$key]);
            }
        }

        return 0.0;
    }

    function updateResellerBalanceDual($pdo, $newBalance)
    {
        $keys = getResellerBalanceKeys();
        $value = normalizeResellerBalanceValue($newBalance);

        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, created_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );

        foreach ($keys as $key) {
            $stmt->execute([$key, $value, $value]);
        }

        return (float)$value;
    }

    function auditResellerBalanceChange($pdo, $oldBalance, $newBalance, $reason)
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
                    'reseller_balance',
                    ?,
                    ?,
                    ?,
                    NOW()
                 )"
            );

            $stmt->execute([
                normalizeResellerBalanceValue($oldBalance),
                normalizeResellerBalanceValue($newBalance),
                $reason
            ]);
        } catch (Exception $e) {
            // Ignore audit table absence if not created
        }
    }

    function syncResellerBalanceKeys($pdo, $forUpdate = false)
    {
        $keys = getResellerBalanceKeys();
        $rows = fetchResellerBalanceRows($pdo, $forUpdate);

        $target = null;
        $oldForAudit = 0.0;

        foreach ($keys as $key) {
            if (isset($rows[$key]) && is_numeric(trim((string)$rows[$key]))) {
                $normalized = normalizeResellerBalanceValue($rows[$key]);
                $target = (float)$normalized;

                if ($oldForAudit === 0.0) {
                    $oldForAudit = (float)$normalized;
                }

                break;
            }
        }

        if ($target === null) {
            $target = 0.0;
        }

        $targetValue = normalizeResellerBalanceValue($target);
        $needsSync = false;

        foreach ($keys as $key) {
            if (!isset($rows[$key])) {
                $needsSync = true;
                break;
            }

            if (normalizeResellerBalanceValue($rows[$key]) !== $targetValue) {
                $needsSync = true;
                break;
            }
        }

        if ($needsSync) {
            updateResellerBalanceDual($pdo, $targetValue);
            auditResellerBalanceChange($pdo, $oldForAudit, $targetValue, 'dual_key_sync');
        }

        return (float)$targetValue;
    }

    function adjustResellerBalanceDual($pdo, $delta, $reason)
    {
        $startedTransaction = false;

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $currentBalance = syncResellerBalanceKeys($pdo, true);
            $newBalance = max(0.00, (float)$currentBalance + (float)$delta);
            $newBalance = (float)normalizeResellerBalanceValue($newBalance);

            updateResellerBalanceDual($pdo, $newBalance);
            auditResellerBalanceChange($pdo, $currentBalance, $newBalance, $reason);

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
