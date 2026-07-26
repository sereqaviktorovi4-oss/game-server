<?php
// Вывод ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php");
    exit;
}

$my_id = (int)$_SESSION['user_id'];
$current_username = $_SESSION['username'] ?? 'Житель';

// 1. Логика обработки действий (принять / отклонить заявку / добавить)
$action = $_GET['action'] ?? '';
$target_id = (int)($_GET['id'] ?? 0);

if ($action === 'accept' && $target_id > 0) {
    pg_query_params($db, "UPDATE friends SET status = 'accepted' WHERE user_id = $1 AND friend_id = $2", array($target_id, $my_id));
    pg_query_params($db, "UPDATE friend_requests SET status = 'accepted' WHERE sender_id = $1 AND receiver_id = $2", array($target_id, $my_id));
    
    $check_f = pg_query_params($db, "SELECT id FROM friends WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)", array($my_id, $target_id));
    if ($check_f && pg_num_rows($check_f) == 0) {
        pg_query_params($db, "INSERT INTO friends (user_id, friend_id, status, created_at) VALUES ($1, $2, 'accepted', NOW())", array($target_id, $my_id));
    }

    $msg = "Житель " . $current_username . " принял вашу заявку в друзья!";
    @pg_query_params($db, "INSERT INTO notifications (user_id, message) VALUES ($1, $2)", array($target_id, $msg));

    header("Location: friends.php");
    exit;
} elseif ($action === 'reject' && $target_id > 0) {
    pg_query_params($db, "DELETE FROM friends WHERE user_id = $1 AND friend_id = $2 AND status = 'pending'", array($target_id, $my_id));
    pg_query_params($db, "DELETE FROM friend_requests WHERE sender_id = $1 AND receiver_id = $2", array($target_id, $my_id));
    header("Location: friends.php");
    exit;
} elseif ($action === 'add' && $target_id > 0 && $target_id !== $my_id) {
    $check_sql = "SELECT id, status FROM friends WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)";
    $check = pg_query_params($db, $check_sql, array($my_id, $target_id));
    
    if ($check && pg_num_rows($check) == 0) {
        $ins_sql = "INSERT INTO friends (user_id, friend_id, status) VALUES ($1, $2, 'pending')";
        pg_query_params($db, $ins_sql, array($my_id, $target_id));
        
        $msg = "Житель " . $current_username . " хочет добавиться к вам в друзья!";
        @pg_query_params($db, "INSERT INTO notifications (user_id, message) VALUES ($1, $2)", array($target_id, $msg));
    }
    header("Location: profile.php?id=" . $target_id . "&status=sent");
    exit;
}

// 2. Логика удаления друга
if (isset($_GET['delete'])) {
    $f_id = (int)$_GET['delete'];
    pg_query_params($db, "DELETE FROM friends WHERE (user_id = $1 AND friend_id = $2) OR (user_id = $2 AND friend_id = $1)", array($my_id, $f_id));
    pg_query_params($db, "DELETE FROM friend_requests WHERE (sender_id = $1 AND receiver_id = $2) OR (sender_id = $2 AND receiver_id = $1)", array($my_id, $f_id));
    header("Location: friends.php");
    exit;
}

include 'header.php';
?>

<div style="max-width: 800px; margin: 20px auto; padding: 0 15px;">

    <!-- СЕКЦИЯ 1: ВХОДЯЩИЕ ЗАЯВКИ -->
    <?php
