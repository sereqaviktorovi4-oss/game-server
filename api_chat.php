<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
include 'db.php'; 

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ОТПРАВКА СООБЩЕНИЯ В ОБЩИЙ ЧАТ
if ($action === 'send') {
    $user_id   = intval($_POST['user_id'] ?? 0);
    $username  = trim($_POST['username'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $room_id   = trim($_POST['room_id'] ?? 'main');
    $is_vip    = intval($_POST['is_vip'] ?? 0);

    if ($user_id <= 0 || empty($message) || empty($username)) {
        echo json_encode(["status" => "error", "message" => "Invalid parameters"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = "INSERT INTO chat (user_id, username, message, room_id, is_vip, created_at) VALUES ($1, $2, $3, $4, $5, NOW())";
    $res = pg_query_params($db, $query, array($user_id, $username, $message, $room_id, $is_vip));

    if ($res) {
        echo json_encode(["status" => "success", "message" => "Message sent"], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => pg_last_error($db)], JSON_UNESCAPED_UNICODE);
    }
}

// ПОЛУЧЕНИЕ СООБЩЕНИЙ ОБЩЕГО ЧАТА
elseif ($action === 'get') {
    $room_id = trim($_GET['room_id'] ?? $_POST['room_id'] ?? 'main');
    $limit   = intval($_GET['limit'] ?? $_POST['limit'] ?? 50);

    $query = "SELECT id, user_id, username, message, room_id, is_vip, created_at FROM chat WHERE room_id = $1 ORDER BY id DESC LIMIT $2";
    $res = pg_query_params($db, $query, array($room_id, $limit));

    $messages = [];
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $messages[] = [
                "id" => intval($row['id']),
                "user_id" => intval($row['user_id']),
                "username" => $row['username'],
                "message" => $row['message'],
                "room_id" => $row['room_id'],
                "is_vip" => intval($row['is_vip']),
                "created_at" => $row['created_at']
            ];
        }
        // Возвращаем в хронологическом порядке (старые сверху, новые снизу)
        $messages = array_reverse($messages);
    }

    echo json_encode(["status" => "success", "messages" => $messages], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(["status" => "error", "message" => "Unknown action"], JSON_UNESCAPED_UNICODE);
}

if (isset($db)) { @pg_close($db); }
?>
