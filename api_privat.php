<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
include 'db.php'; 

// Читаем и JSON (для Godot), и обычные параметры (для сайта)
$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);

if (is_array($json_input)) {
    $request_data = $json_input;
} else {
    $request_data = array_merge($_GET, $_POST);
}

$action = $request_data['action'] ?? '';

// ОТПРАВКА ЛИЧНОГО СООБЩЕНИЯ
if ($action === 'send' || $action === 'send_message') {
    $sender_id   = intval($request_data['sender_id'] ?? $request_data['user_id'] ?? 0);
    $receiver_id = intval($request_data['receiver_id'] ?? $request_data['target_id'] ?? 0);
    $message     = trim($request_data['message'] ?? '');
    $is_vip      = intval($request_data['is_vip'] ?? 0);

    if ($sender_id <= 0 || $receiver_id <= 0 || empty($message)) {
        echo json_encode(["status" => "error", "message" => "Invalid parameters"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = "INSERT INTO messages (sender_id, receiver_id, message, is_vip, is_read, created_at) VALUES ($1, $2, $3, $4, 'false', NOW())";
    $res = pg_query_params($db, $query, array($sender_id, $receiver_id, $message, $is_vip));

    if ($res) {
        echo json_encode(["status" => "success", "message" => "Private message sent"], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => pg_last_error($db)], JSON_UNESCAPED_UNICODE);
    }
}

// ПОЛУЧЕНИЕ ЛИЧНЫХ СООБЩЕНИЙ МЕЖДУ ДВУМЯ ПОЛЬЗОВАТЕЛЯМИ
elseif ($action === 'get' || $action === 'get_history') {
    $user_1 = intval($request_data['user_1'] ?? $request_data['user_id'] ?? 0);
    $user_2 = intval($request_data['user_2'] ?? $request_data['target_id'] ?? 0);
    $limit  = intval($request_data['limit'] ?? 50);

    if ($user_1 <= 0 || $user_2 <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid users"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = "SELECT id, sender_id, receiver_id, message, is_vip, is_read, created_at 
              FROM messages 
              WHERE (sender_id = $1 AND receiver_id = $2) OR (sender_id = $2 AND receiver_id = $1) 
              ORDER BY id DESC LIMIT $3";
              
    $res = pg_query_params($db, $query, array($user_1, $user_2, $limit));

    $messages = [];
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $messages[] = [
                "id" => intval($row['id']),
                "sender_id" => intval($row['sender_id']),
                "receiver_id" => intval($row['receiver_id']),
                "message" => $row['message'],
                "is_vip" => intval($row['is_vip']),
                "is_read" => ($row['is_read'] === 't' || $row['is_read'] === true || $row['is_read'] === '1'),
                "created_at" => $row['created_at']
            ];
        }
        $messages = array_reverse($messages);
    }

    echo json_encode(["status" => "success", "messages" => $messages], JSON_UNESCAPED_UNICODE);
}

// ОТМЕТИТЬ СООБЩЕНИЯ КАК ПРОЧИТАННЫЕ
elseif ($action === 'mark_read') {
    $receiver_id = intval($request_data['receiver_id'] ?? 0);
    $sender_id   = intval($request_data['sender_id'] ?? 0);

    if ($receiver_id <= 0 || $sender_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid parameters"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = "UPDATE messages SET is_read = 'true' WHERE receiver_id = $1 AND sender_id = $2 AND is_read = 'false'";
    $res = pg_query_params($db, $query, array($receiver_id, $sender_id));

    if ($res) {
        echo json_encode(["status" => "success", "message" => "Marked as read"], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => pg_last_error($db)], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Unknown action: " . $action], JSON_UNESCAPED_UNICODE);
}

if (isset($db)) { @pg_close($db); }
?>
