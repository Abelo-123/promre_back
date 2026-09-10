<?php
/**
 * Chat Support Routes
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

$initData = isset($requestData['initData']) ? $requestData['initData'] : '';
$action = isset($requestData['action']) ? $requestData['action'] : '';
$message = isset($requestData['message']) ? $requestData['message'] : '';

$tgId = getTelegramUserId($initData);
if (!$tgId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    if ($action === 'send') {
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message content is empty']);
            exit;
        }
        
        $stmt = $pdo->prepare('INSERT INTO chat_messages (user_id, message, is_admin, created_at) VALUES (:user_id, :message, 0, NOW())');
        $stmt->execute(['user_id' => $tgId, 'message' => $message]);
        
        echo json_encode(['success' => true]);
        
    } elseif ($action === 'fetch') {
        $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE user_id = :user_id ORDER BY created_at ASC LIMIT 100');
        $stmt->execute(['user_id' => $tgId]);
        $rows = $stmt->fetchAll();
        
        // Normalize outputs
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['is_admin'] = (int)$r['is_admin'];
        }
        
        echo json_encode(['success' => true, 'messages' => $rows]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    // If the chat_messages table doesn't exist, gracefully fail on fetch with empty array
    if ($action === 'fetch') {
        echo json_encode(['success' => true, 'messages' => []]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
exit;
