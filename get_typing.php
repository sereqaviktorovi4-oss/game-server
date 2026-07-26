<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$my_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Явно приводим NOW() и вычисление к типу timestamp, а также используем параметр для ID
$sql = "SELECT username FROM users WHERE is_typing_at > (CURRENT_TIMESTAMP - INTERVAL '4 seconds') AND id != $1 LIMIT 1";
$res = pg_query_params($db, $sql, array($my_id));

if ($res && pg_num_rows($res) > 0) {
    $row = pg_fetch_assoc($res);
    echo htmlspecialchars($row['username']);
} else {
    echo ""; // Никто не пишет
}

if (isset($db)) { @pg_close($db); }
?>
