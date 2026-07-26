<?php
include 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    exit("NOT_AUTH"); 
}

$user_id = (int)$_SESSION['user_id'];

// Начало транзакции
$tx = pg_query($db, "BEGIN");
if (!$tx) {
    if (isset($db)) { @pg_close($db); }
    exit("TRANSACTION_ERROR");
}

// Берем ник и баланс с блокировкой строки
$res_u = pg_query_params($db, "SELECT username, citymoney FROM users WHERE id = $1 FOR UPDATE", array($user_id));
$u_data = ($res_u) ? pg_fetch_assoc($res_u) : null;

if (!$u_data) {
    pg_query($db, "ROLLBACK");
    if (isset($db)) { @pg_close($db); }
    exit("USER_NOT_FOUND");
}

$username = $u_data['username'];
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$is_vip = (isset($_POST['is_vip']) && $_POST['is_vip'] == '1') ? 1 : 0;

if (empty($message)) { 
    pg_query($db, "ROLLBACK");
    if (isset($db)) { @pg_close($db); }
    exit("EMPTY_MSG"); 
}

// Если сообщение VIP — проверяем и списываем 10 CM
if ($is_vip === 1) {
    $price = 10;
    if ((int)$u_data['citymoney'] < $price) {
        pg_query($db, "ROLLBACK");
        if (isset($db)) { @pg_close($db); }
        exit("ERROR_MONEY"); 
    }
    $update_money = pg_query_params($db, "UPDATE users SET citymoney = citymoney - $1 WHERE id = $2", array($price, $user_id));
    if (!$update_money) {
        pg_query($db, "ROLLBACK");
        if (isset($db)) { @pg_close($db); }
        exit("SQL_ERROR: " . pg_last_error($db));
    }
}

// Куда отправляем?
if (isset($_POST['receiver_id']) && (int)$_POST['receiver_id'] > 0) {
    // 1. ЛИЧНОЕ СООБЩЕНИЕ
    $receiver_id = (int)$_POST['receiver_id'];
    $sql = "INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at, is_vip) 
            VALUES ($1, $2, $3, FALSE, NOW(), $4)";
    $params = array($user_id, $receiver_id, $message, $is_vip);
} else {
    // 2. ОБЩИЙ ЧАТ (проверяем, есть ли колонка is_vip в таблице chat, если нет — пишем без неё)
    // Безопасный запрос для общего чата
    $sql = "INSERT INTO chat (user_id, username, message, is_vip) VALUES ($1, $2, $3, $4)";
    $params = array($user_id, $username, $message, $is_vip);
}

$result = pg_query_params($db, $sql, $params);
if ($result) {
    pg_query($db, "COMMIT");
    echo "SUCCESS";
} else {
    pg_query($db, "ROLLBACK");
    echo "SQL_ERROR: " . pg_last_error($db);
}

if (isset($db)) { @pg_close($db); }
?>
