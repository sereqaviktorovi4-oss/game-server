<?php
// Вывод ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$my_id = (int)$_SESSION['user_id'];
$to_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;

if ($to_id <= 0) {
    exit("Invalid user ID");
}

try {
    // Помечаем сообщения как прочитанные
    $update_sql = "UPDATE messages SET is_read = TRUE WHERE sender_id = $1 AND receiver_id = $2";
    $update_res = pg_query_params($db, $update_sql, array($to_id, $my_id));

    if (!$update_res) {
        throw new Exception(pg_last_error($db));
    }

    // Выбираем переписку с помощью безопасных параметров
    $sql = "SELECT * FROM messages 
            WHERE (sender_id = $1 AND receiver_id = $2) 
               OR (sender_id = $2 AND receiver_id = $1) 
            ORDER BY created_at ASC";

    $res = pg_query_params($db, $sql, array($my_id, $to_id));

    if (!$res) {
        throw new Exception(pg_last_error($db));
    }

    while ($m = pg_fetch_assoc($res)) {
        $msg_sender_id = isset($m['sender_id']) ? (int)$m['sender_id'] : 0;
        $class = ($msg_sender_id === $my_id) ? 'sent' : 'rcvd';
        
        $vip_class = (isset($m['is_vip']) && ((int)$m['is_vip'] === 1)) ? 'vip-msg' : '';
        
        $is_read_flag = isset($m['is_read']) && ($m['is_read'] === 't' || $m['is_read'] === true || $m['is_read'] == 1);
        $status = ($class === 'sent') ? ($is_read_flag ? ' <i class="fa-solid fa-check-double"></i>' : ' <i class="fa-solid fa-check"></i>') : '';
        
        $time_str = isset($m['created_at']) ? date('H:i', strtotime($m['created_at'])) : '';
        $safe_message = htmlspecialchars($m['message'] ?? '');

        echo "<div class='msg-row {$class} {$vip_class}'>
                <div class='bubble'>
                    {$safe_message}
                    <span class='time'>{$time_str}{$status}</span>
                </div>
              </div>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
} finally {
    if (isset($db)) { 
        @pg_close($db); 
    }
}
?>
