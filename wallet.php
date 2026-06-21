<?php
/**
 * Wallet Transactions Helper
 */

require_once __DIR__ . '/config.php';

/**
 * Process a user wallet transaction atomically.
 * Must be executed within a PDO transaction.
 *
 * @param string $tgId - Telegram user ID
 * @param string $type - Transaction type (deposit, order, refund, etc.)
 * @param float $amount - Amount to credit (positive) or debit (negative)
 * @param string $description - Transaction description
 * @param PDO $pdo - Active PDO database handle
 * @param string|null $refType - Optional reference table name
 * @param int|null $refId - Optional reference primary key
 * @return float New balance
 */
function processTransaction($tgId, $type, $amount, $description, $pdo, $refType = null, $refId = null) {
    $botId = getCurrentBotId();
    
    // 1. Update User Balance
    $stmt = $pdo->prepare('UPDATE auth SET balance = balance + :amount WHERE tg_id = :tg_id AND bot_id = :bot_id');
    $stmt->execute(['amount' => $amount, 'tg_id' => $tgId, 'bot_id' => $botId]);

    // 2. Fetch New Balance
    $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
    $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new Exception("User not found: {$tgId}");
    }
    $newBalance = (float)$user['balance'];

    // 3. Log to Transactions Ledger
    $stmt = $pdo->prepare('
        INSERT INTO transactions (user_id, bot_id, type, amount, balance_after, reference_type, reference_id, description) 
        VALUES (:user_id, :bot_id, :type, :amount, :balance_after, :reference_type, :reference_id, :description)
    ');
    $stmt->execute([
        'user_id'        => $tgId,
        'bot_id'         => $botId,
        'type'           => $type,
        'amount'         => $amount,
        'balance_after'  => $newBalance,
        'reference_type' => $refType,
        'reference_id'   => $refId,
        'description'    => $description
    ]);

    // 4. Handle Referral Commissions (25% on first deposit, 7% on subsequent deposits)
    if ($type === 'deposit' && $amount > 0) {
        try {
            $stmt = $pdo->prepare('SELECT referred_by FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
            $uRow = $stmt->fetch();
            
            if ($uRow && !empty($uRow['referred_by'])) {
                $referrerId = (string)$uRow['referred_by'];
                
                // Determine if this is the first successful deposit
                $isFirstDeposit = true;
                if ($refType === 'deposit' && !empty($refId)) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM deposits WHERE user_id = :user_id AND bot_id = :bot_id AND status = 'success' AND id != :current_deposit_id");
                    $stmt->execute(['user_id' => $tgId, 'bot_id' => $botId, 'current_deposit_id' => $refId]);
                    $depRow = $stmt->fetch();
                    $otherSuccessfulCount = $depRow ? (int)$depRow['cnt'] : 0;
                    $isFirstDeposit = ($otherSuccessfulCount === 0);
                } else {
                    // Fallback using transactions count
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE user_id = :user_id AND bot_id = :bot_id AND type = 'deposit'");
                    $stmt->execute(['user_id' => $tgId, 'bot_id' => $botId]);
                    $txRow = $stmt->fetch();
                    $txCount = $txRow ? (int)$txRow['cnt'] : 0;
                    $isFirstDeposit = ($txCount <= 1);
                }

                // 4a. Award 20% first deposit bonus to the referred user
                if ($isFirstDeposit) {
                    $bonusAmount = $amount * 0.20;
                    
                    // Update user balance
                    $stmt = $pdo->prepare('UPDATE auth SET balance = balance + :bonus WHERE tg_id = :tg_id AND bot_id = :bot_id');
                    $stmt->execute(['bonus' => $bonusAmount, 'tg_id' => $tgId, 'bot_id' => $botId]);
                    
                    // Fetch fresh balance
                    $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                    $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
                    $depFreshRow = $stmt->fetch();
                    $depNewBal = $depFreshRow ? (float)$depFreshRow['balance'] : $newBalance + $bonusAmount;
                    
                    // Log to Transactions Ledger
                    $stmt = $pdo->prepare('
                        INSERT INTO transactions (user_id, bot_id, type, amount, balance_after, reference_type, reference_id, description) 
                        VALUES (:user_id, :bot_id, :type, :amount, :balance_after, :reference_type, :reference_id, :description)
                    ');
                    $stmt->execute([
                        'user_id'        => $tgId,
                        'bot_id'         => $botId,
                        'type'           => 'referral_first_deposit_bonus',
                        'amount'         => $bonusAmount,
                        'balance_after'  => $depNewBal,
                        'reference_type' => $refType,
                        'reference_id'   => $refId,
                        'description'    => '20% bonus on first deposit'
                    ]);
                    
                    // Add in-app alert for depositor
                    $stmt = $pdo->prepare('
                        INSERT INTO alerts (user_id, bot_id, title, message, type) 
                        VALUES (:user_id, :bot_id, :title, :message, :type)
                    ');
                    $stmt->execute([
                        'user_id' => $tgId,
                        'bot_id'  => $botId,
                        'title'   => 'First Deposit Bonus',
                        'message' => "You received " . number_format($bonusAmount, 2) . " ETB bonus (20%) on your first deposit!",
                        'type'    => 'success'
                    ]);
                    
                    // Update return balance variable
                    $newBalance = $depNewBal;
                }

                // 4b. Award flat 7% commission to referrer
                $commissionRate = 0.07;
                $commission = $amount * $commissionRate;
                $percentageText = '7%';

                // Update referrer balance
                $stmt = $pdo->prepare('UPDATE auth SET balance = balance + :commission WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['commission' => $commission, 'tg_id' => $referrerId, 'bot_id' => $botId]);

                // Fetch referrer new balance
                $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['tg_id' => $referrerId, 'bot_id' => $botId]);
                $rRow = $stmt->fetch();
                $refNewBal = $rRow ? (float)$rRow['balance'] : 0.0;

                // Log ledger transaction for referrer
                $stmt = $pdo->prepare('
                    INSERT INTO transactions (user_id, bot_id, type, amount, balance_after, reference_type, reference_id, description) 
                    VALUES (:user_id, :bot_id, :type, :amount, :balance_after, :reference_type, :reference_id, :description)
                ');
                $stmt->execute([
                    'user_id'        => $referrerId,
                    'bot_id'         => $botId,
                    'type'           => 'referral_commission',
                    'amount'         => $commission,
                    'balance_after'  => $refNewBal,
                    'reference_type' => 'referral_user',
                    'reference_id'   => $tgId,
                    'description'    => "{$percentageText} referral commission from user #{$tgId} deposit"
                ]);

                // Add in-app notification alert for referrer
                $stmt = $pdo->prepare('
                    INSERT INTO alerts (user_id, bot_id, title, message, type) 
                    VALUES (:user_id, :bot_id, :title, :message, :type)
                ');
                $stmt->execute([
                    'user_id' => $referrerId,
                    'bot_id'  => $botId,
                    'title'   => 'Referral Commission',
                    'message' => "You earned " . number_format($commission, 2) . " ETB ({$percentageText}) from your referred friend's deposit!",
                    'type'    => 'success'
                ]);
            }
        } catch (Exception $e) {
            // Log/ignore commission errors to keep main deposit succeeding
            error_log('Failed to reward referral commission: ' . $e->getMessage());
        }
    }

    return $newBalance;
}
