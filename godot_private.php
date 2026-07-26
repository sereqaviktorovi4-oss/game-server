<?php
// Отключаем вывод предупреждений в тело ответа, чтобы не ломать JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Заголовки для работы с Godot (всегда отдаем JSON)
header('Content-Type: application/json; charset=utf-8');

include 'db.php';

// Читаем сырые данные (JSON), пришедшие из Godot
$raw_input = file_get_contents('php://input');
$request = json_decode($raw_input, true);

if (!is_array($request) || !isset($request['action'])) {
    echo json_encode(["error" => "INVALID_REQUEST"]);
    if (isset($db)) { @pg_close($db); }
    exit;
}

$user_id = isset($request['user_id']) ? (int)$request['user_id'] : 0;
$action = $request['action'];

// Проверка авторизации пользователя
if ($user_id <= 0) {
    echo json_encode(["error" => "UNAUTHORIZED_API_USER"]);
    if (isset($db)) { @pg_close($db); }
    exit;
}

// 1. Получить список активных диалогов пользователя
if ($action === 'get_chats') {
    $sql = "SELECT DISTINCT 
                CASE WHEN sender_id = $1 THEN receiver_id ELSE sender_id END AS partner_id 
            FROM messages 
            WHERE sender_id = $1 OR receiver_id = $1";
    $res = pg_query_params($db, $sql, array($user_id));
    
    $chats = [];
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $p_id = (int)$row['partner_id'];
            $u_res = pg_query_params($db, "SELECT username FROM users WHERE id = $1", array($p_id));
            if ($u_res && pg_num_rows($u_res) > 0) {
                $u_data = pg_fetch_assoc($u_res);
                $chats[] = [
                    "id" => $p_id,
                    "username" => $u_data['username']
                ];
            }
        }
    }
    echo json_encode(["chats" => $chats]);
    if (isset($db)) { @pg_close($db); }
    exit;
}

// 2. Получить историю переписки с конкретным пользователем
if ($action === 'get_history') {
    $target_id = isset($request['target_id']) ? (int)$request['target_id'] : 0;
    
    $sql = "SELECT m.id, m.sender_id, m.message, m.created_at, m.is_vip, u.username AS sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = $1 AND m.receiver_id = $2)
               OR (m.sender_id = $2 AND m.receiver_id = $1)
            ORDER BY m.id ASC LIMIT 100";
            
    $res = pg_query_params($db, $sql, array($user_id, $target_id));
    $messages = [];
    
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $text = $row['message'];
            if ((int)$row['is_vip'] === 1) {
                $text = "[color=gold][b]👑 " . $text . "[/b][/color]";
            }
            
            $messages[] = [
                "id" => (int)$row['id'],
                "sender_id" => (int)$row['sender_id'],
                "sender" => $row['sender_name'],
                "text" => $text,
                "time" => date("H:i", strtotime($row['created_at']))
            ];
        }
    }
    echo json_encode(["messages" => $messages]);
    if (isset($db)) { @pg_close($db); }
    exit;
}

// 3. Отправить сообщение
if ($action === 'send_message') {
    $target_id = isset($request['target_id']) ? (int)$request['target_id'] : 0;
    $message = isset($request['message']) ? trim($request['message']) : '';
    $is_vip = isset($request['is_vip']) ? (int)$request['is_vip'] : 0;

    if ($target_id <= 0 || $message === '') {
        echo json_encode(["error" => "EMPTY_DATA"]);
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    if ($is_vip === 1) {
        $user_res = pg_query_params($db, "SELECT citymoney FROM users WHERE id = $1", array($user_id));
        $user_data = ($user_res) ? pg_fetch_assoc($user_res) : null;
        if (!$user_data || (int)$user_data['citymoney'] < 10) {
            echo json_encode(["error" => "ERROR_MONEY"]);
            if (isset($db)) { @pg_close($db); }
            exit;
        }
        pg_query_params($db, "UPDATE users SET citymoney = citymoney - 10 WHERE id = $1", array($user_id));
    }

    $insert = "INSERT INTO messages (sender_id, receiver_id, message, is_vip, created_at) 
               VALUES ($1, $2, $3, $4, NOW()) RETURNING id";
    
    $res = pg_query_params($db, $insert, array($user_id, $target_id, $message, $is_vip));
    if ($res) {
        $row = pg_fetch_assoc($res);
        echo json_encode(["status" => "OK", "message_id" => (int)$row['id']]);
    } else {
        echo json_encode(["error" => "DB_ERROR"]);
    }
    
    if (isset($db)) { @pg_close($db); }
    exit;
}

// Если действие не распознано
echo json_encode(["error" => "UNKNOWN_ACTION"]);
if (isset($db)) { @pg_close($db); }
exit;
?>
