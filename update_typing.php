<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (isset($_SESSION['user_id']) && isset($_GET['status'])) {
    $uid = (int)$_SESSION['user_id'];
    $status = (int)$_GET['status'];
    // Обновляем время печати
    $time = ($status == 1) ? time() : 0;
    
    // Используем параметризованный запрос для безопасности
    pg_query_params($db, "UPDATE users SET is_typing_at = $1 WHERE id = $2", array($time, $uid));
}

if (isset($db)) { @pg_close($db); }
?>
