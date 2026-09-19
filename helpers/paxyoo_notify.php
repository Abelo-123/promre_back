<?php

function notifyPaxyooAdminBalanceChange($pdo): void
{
    try {
        $syncUrl = trim((string)getenv('PAXYOO_SYNC_URL'));

        if ($syncUrl === '') {
            $syncUrl = 'https://padmin121-1.onrender.com/api/reseller/sync-balances';
        }

        $token = trim((string)getenv('PAXYOO_BEARER_TOKEN'));

        if ($token === '') {
            $token = 'paxyo2026';
        }

        $stmt = $pdo->query(
            "SELECT setting_key, setting_value
             FROM settings
             WHERE setting_key IN ('reseller_balance_primore', 'total_deposit')"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $balance = $rows['reseller_balance_primore'] ?? null;
        $totalDeposit = $rows['total_deposit'] ?? null;

        if ($balance === null && $totalDeposit === null) {
            return;
        }

        $payload = json_encode([
            'reseller_balance' => $balance !== null ? (float)$balance : null,
            'total_deposit' => $totalDeposit !== null ? (float)$totalDeposit : null
        ]);

        $ch = curl_init($syncUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_CONNECTTIMEOUT_MS => 1500
        ]);

        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[paxyoo_notify] ' . $e->getMessage());
    }
}
