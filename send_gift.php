<?php
// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Простая проверка авторизации
if (!isset($_SESSION['user_id'])) {
    if (isset($db)) { @pg_close($db); }
    die("AUTH_ERROR");
}

$sender_id = (int)$_SESSION['user_id'];
$to_id = isset($_POST['to_id']) ? (int)$_POST['to_id'] : 0;
$gift_icon = isset($_POST['icon']) ? trim($_POST['icon']) : '';
$price = 30;

if ($to_id <= 0 || empty($gift_icon)) {
    if (isset($db)) { @pg_close($db); }
    die("INVALID_DATA");
}

// Начало транзакции для обеспечения целостности данных (баланс и отправка подарка)
$tx = pg_query($db, "BEGIN");
if (!$tx) {
    if (isset($db)) { @pg_close($db); }
    die("TRANSACTION_ERROR");
}

// 2. Проверяем баланс с блокировкой строки для предотвращения гонки потоков (SELECT ... FOR UPDATE)
$res = pg_query_params($db, "SELECT citymoney FROM users WHERE id = $1 FOR UPDATE", array($sender_id));
if (!$res) {
    pg_query($db, "ROLLBACK");
    if (isset($db)) { @pg_close($db); }
    die("DATABASE_ERROR_USERS: " . pg_last_error($db));
}

$user_data = pg_fetch_assoc($res);

if (!$user_data || (int)$user_data['citymoney'] < $price) {
    pg_query($db, "ROLLBACK");
    if (isset($db)) { @pg_close($db); }
    die("LOW_MONEY");
}

// 3. Снимаем деньги (используем параметризованный запрос)
$update = pg_query_params($db, "UPDATE users SET citymoney = citymoney - $1 WHERE id = $2", array($price, $sender_id));
if (!$update) {
    pg_query($db, "ROLLBACK");
    if (isset($db)) { @pg_close($db); }
    die("DATABASE_ERROR_UPDATE: " . pg_last_error($db));
}

// 4. Записываем подарок в таблицу user_gifts с помощью безопасных параметров
$insert = pg_query_params($db, "INSERT INTO user_gifts (sender_id, receiver_id, gift_icon) VALUES ($1, $2, $3)", array($sender_id, $to_id, $gift_icon));

if ($insert) {
    pg_query($db, "COMMIT");
    echo "SUCCESS";
} else {
    pg_query($db, "ROLLBACK");
    echo "DB_INSERT_ERROR: " . pg_last_error($db);
}

if (isset($db)) { @pg_close($db); }
?>
