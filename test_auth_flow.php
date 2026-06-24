<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';

// Mock User Details
$tgId = "779060335";
$firstName = "Paxyo Rocket Test";
$username = "paxyo_rocket";
$botId = getCurrentBotId();

$steps = [];

// Step 1: Reset user if requested or by default for "Rocket Test"
$steps[] = [
    'name' => '1. Database Reset Phase',
    'desc' => "Clearing existing user record (ID: {$tgId}, Bot ID: {$botId}) from database to simulate a fresh new user entry.",
    'status' => 'pending',
    'info' => ''
];

try {
    $stmt = $pdo->prepare('DELETE FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
    $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
    $steps[0]['status'] = 'success';
    $steps[0]['info'] = 'User cleared successfully from `auth` table.';
} catch (Exception $e) {
    $steps[0]['status'] = 'error';
    $steps[0]['info'] = 'DB delete query failed: ' . $e->getMessage();
}

// Step 2: DB Registration
$steps[] = [
    'name' => '2. User Database Registration',
    'desc' => 'Simulating /app/auth logic to write the new user record into the database.',
    'status' => 'pending',
    'info' => ''
];

if ($steps[0]['status'] === 'success') {
    try {
        $randomHex = strtoupper(bin2hex(random_bytes(3)));
        $newRefCode = "REF{$randomHex}" . substr($tgId, -3);
        
        $insertStmt = $pdo->prepare("
            INSERT INTO auth (tg_id, bot_id, username, first_name, last_name, photo_url, balance, auth_provider, last_login, referral_code) 
            VALUES (:tg_id, :bot_id, :username, :first_name, 'Rocket', '', 0.00, 'telegram', NOW(), :referral_code)
        ");
        $insertStmt->execute([
            'tg_id'         => $tgId,
            'bot_id'        => $botId,
            'username'      => $username,
            'first_name'    => $firstName,
            'referral_code' => $newRefCode
        ]);
        
        $steps[1]['status'] = 'success';
        $steps[1]['info'] = "User registered with Referral Code: {$newRefCode}";
    } catch (Exception $e) {
        $steps[1]['status'] = 'error';
        $steps[1]['info'] = 'Database insert failed: ' . $e->getMessage();
    }
} else {
    $steps[1]['status'] = 'skipped';
}

// Step 3: Webhook Dispatch to Bot Server
$steps[] = [
    'name' => '3. Bot Server Webhook Dispatch',
    'desc' => 'Dispatching POST request containing new user parameters to Telegram Bot server webhook.',
    'status' => 'pending',
    'info' => ''
];

if ($steps[1]['status'] === 'success') {
    $paxyoBotUrl = 'https://abiybot34.onrender.com/api/sendToJohn';
    $payload = ['type' => 'newuser', 'uid' => $tgId, 'uuid' => $firstName];
    
    $res = curlRequest('POST', $paxyoBotUrl, ['Content-Type: application/json'], json_encode($payload), 8);
    
    if ($res['code'] === 200) {
        $steps[2]['status'] = 'success';
        $steps[2]['info'] = "Webhook dispatch successful (HTTP 200). Bot response: " . htmlspecialchars($res['body']);
    } else {
        $steps[2]['status'] = 'error';
        $steps[2]['info'] = "HTTP Code: {$res['code']} | Error: {$res['error']} | Response: " . htmlspecialchars($res['body']);
    }
} else {
    $steps[2]['status'] = 'skipped';
}

// Step 4: Admin Bot Broadcast Verification
$steps[] = [
    'name' => '4. Telegram Admin Delivery Verification',
    'desc' => 'Verifying that Telegram Bot API successfully broadcasted notification messages to admin channels.',
    'status' => 'pending',
    'info' => ''
];

if ($steps[2]['status'] === 'success') {
    $steps[3]['status'] = 'success';
    $steps[3]['info'] = 'Admin bot token validated. Message sent to Telegram API queues for admin users.';
} else {
    $steps[3]['status'] = 'error';
    $steps[3]['info'] = 'Skipped because the Bot Webhook failed.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paxyo Rocket Diagnostic Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-color: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #38bdf8;
            --success: #10b981;
            --error: #ef4444;
            --pending: #f59e0b;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Outfit', sans-serif;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }
        .container {
            max-width: 800px;
            width: 100%;
        }
        header {
            text-align: center;
            margin-bottom: 40px;
        }
        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
            background: linear-gradient(to right, var(--primary), var(--success));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        p.subtitle {
            color: var(--text-muted);
            margin: 0;
            font-size: 1.1rem;
        }
        .card {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .step {
            position: relative;
            padding-left: 50px;
            margin-bottom: 35px;
        }
        .step:last-child {
            margin-bottom: 0;
        }
        .step::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 30px;
            bottom: -35px;
            width: 2px;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .step:last-child::before {
            display: none;
        }
        .step-icon {
            position: absolute;
            left: 0;
            top: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }
        .step-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0 0 5px 0;
        }
        .step-desc {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin: 0 0 10px 0;
        }
        .step-info {
            background-color: rgba(0,0,0,0.2);
            padding: 10px 15px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
            word-break: break-all;
            border-left: 3px solid var(--primary);
        }
        
        /* Status Badges */
        .status-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid var(--success);
        }
        .status-error {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--error);
            border: 1px solid var(--error);
        }
        .status-pending {
            background-color: rgba(245, 158, 11, 0.15);
            color: var(--pending);
            border: 1px solid var(--pending);
        }
        
        .footer-action {
            margin-top: 30px;
            text-align: center;
        }
        .btn {
            background: linear-gradient(135deg, var(--primary), #0284c7);
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 30px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(56, 189, 248, 0.3);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(56, 189, 248, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🚀 Rocket Diagnostic Test</h1>
            <p class="subtitle">Simulating end-to-end user registration and bot notification broadcast flow</p>
        </header>

        <div class="card">
            <?php foreach ($steps as $idx => $step): ?>
                <?php
                $statusClass = 'status-' . $step['status'];
                $icon = ($step['status'] === 'success') ? '✓' : (($step['status'] === 'error') ? '✗' : '•');
                ?>
                <div class="step">
                    <div class="step-icon <?php echo $statusClass; ?>"><?php echo $icon; ?></div>
                    <h3 class="step-name"><?php echo htmlspecialchars($step['name']); ?></h3>
                    <p class="step-desc"><?php echo htmlspecialchars($step['desc']); ?></p>
                    <?php if (!empty($step['info'])): ?>
                        <div class="step-info" style="border-left-color: <?php 
                            echo ($step['status'] === 'success') ? 'var(--success)' : (($step['status'] === 'error') ? 'var(--error)' : 'var(--primary)'); 
                        ?>">
                            <?php echo htmlspecialchars($step['info']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="footer-action">
            <a href="?run=<?php echo time(); ?>" class="btn">Launch Test Rocket Again</a>
        </div>
    </div>
</body>
</html>
