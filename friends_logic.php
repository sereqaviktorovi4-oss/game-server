<?php
include 'db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    if (isset($db)) { @pg_close($db); }
    die("Ошибка авторизации.");
}

$my_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$target_id = (int)($_GET['id'] ?? 0);

if ($target_id <= 0 || $target_id === $my_id) {
    if (isset($db)) { @pg_close($db); }
    die("Некорректный ID пользователя.");
}

// Получаем имя текущего пользователя из сессии или базы данных
$current_username = $_SESSION['username'] ?? 'Житель';

// 1. ДОБАВЛЕНИЕ В ДРУЗЬЯ
if ($action === 'add') {
    // Проверяем, нет ли уже заявки или дружбы
    $check_sql = "SELECT id, status FROM friends WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)";
    $check = pg_query_params($db, $check_sql, array($my_id, $target_id));
    
    if ($check && pg_num_rows($check) == 0) {
        // Создаем заявку в таблицу friends (кто отправил -> кому, статус pending)
        $ins_sql = "INSERT INTO friends (user_id, friend_id, status) VALUES ($1, $2, 'pending')";
        pg_query_params($db, $ins_sql, array($my_id, $target_id));
        
        // Создаем системное уведомление для получателя
        $msg = "Житель " . $current_username . " хочет добавиться к вам в друзья!";
        $notif_sql = "INSERT INTO notifications (user_id, message) VALUES ($1, $2)";
        pg_query_params($db, $notif_sql, array($target_id, $msg));
    }

    if (isset($db)) { @pg_close($db); }
    // Возвращаем пользователя назад на страницу профиля или список друзей вместо пустого экрана
    header("Location: profile.php?id=" . $target_id . "&status=sent");
    exit;
}

// 2. ПОДТВЕРЖДЕНИЕ ЗАЯВКИ
if ($action === 'accept') {
    // Обновляем статус на 'accepted' (проверяем оба варианта направления)
    $accept_sql = "UPDATE friends SET status = 'accepted' WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)";
    pg_query_params($db, $accept_sql, array($target_id, $my_id));
    
    // Уведомляем того, кто кидал заявку
    $msg = "Житель " . $current_username . " принял вашу заявку в друзья!";
    $notif_sql = "INSERT INTO notifications (user_id, message) VALUES ($1, $2)";
    pg_query_params($db, $notif_sql, array($target_id, $msg));
    
    if (isset($db)) { @pg_close($db); }
    header("Location: friends.php?msg=success");
    exit;
}

// 3. УДАЛЕНИЕ ИЗ ДРУЗЕЙ / ОТКЛОНЕНИЕ
if ($action === 'delete' || $action === 'reject') {
    $del_sql = "DELETE FROM friends WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)";
    pg_query_params($db, $del_sql, array($my_id, $target_id));
    
    if (isset($db)) { @pg_close($db); }
    header("Location: friends.php");
    exit;
}

if (isset($db)) { @pg_close($db); }
header("Location: friends.php");
exit;
?>
