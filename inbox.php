<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php"); 
    exit; 
}

$my_id = (int)$_SESSION['user_id'];

// Безопасный и полностью оптимизированный запрос для PostgreSQL с использованием pg_query_params и корректной булевой проверки is_read = FALSE
$sql = "
    SELECT u.id, u.username, u.avatar_path, u.gender,
    MAX(m.created_at) as last_date,
    (SELECT message FROM messages 
     WHERE (sender_id = u.id AND receiver_id = $1) 
        OR (sender_id = $1 AND receiver_id = u.id) 
     ORDER BY created_at DESC LIMIT 1) as last_msg,
    (SELECT COUNT(*) FROM messages 
     WHERE sender_id = u.id AND receiver_id = $1 AND is_read = FALSE) as unread_count
    FROM users u
    JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
    WHERE (m.sender_id = $1 OR m.receiver_id = $1) AND u.id != $1
    GROUP BY u.id, u.username, u.avatar_path, u.gender
    ORDER BY unread_count DESC, last_date DESC
";

$query = pg_query_params($db, $sql, array($my_id));

// Подключаем шапку после проверки сессии и инициализации переменных
include 'header.php'; 
?>

<div style="max-width: 600px; margin: 0 auto; padding: 20px 10px;">
    <h2 style="color: var(--accent); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid fa-envelope"></i> Мои сообщения
    </h2>

    <div style="display: flex; flex-direction: column; gap: 12px;">
        <?php if ($query && pg_num_rows($query) > 0): ?>
            <?php while($chat = pg_fetch_assoc($query)): 
                $chat_id = isset($chat['id']) ? (int)$chat['id'] : 0;
                $chat_username = isset($chat['username']) ? $chat['username'] : 'Житель';
                $chat_avatar = isset($chat['avatar_path']) ? $chat['avatar_path'] : '';
                $chat_gender = isset($chat['gender']) ? $chat['gender'] : 'm';
                $unread_count = isset($chat['unread_count']) ? (int)$chat['unread_count'] : 0;
                $last_date = isset($chat['last_date']) ? $chat['last_date'] : '';
                $last_msg = isset($chat['last_msg']) ? $chat['last_msg'] : '';
            ?>
                <a href="messages.php?to=<?php echo $chat_id; ?>" 
                   style="text-decoration: none; display: flex; align-items: center; gap: 15px; background: #1c1c24; padding: 15px; border-radius: 20px; border: 1px solid <?php echo $unread_count > 0 ? 'var(--accent)' : '#2a2a35'; ?>; transition: 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                    
                    <div style="width: 55px; height: 55px; position: relative; flex-shrink: 0;">
                        <?php if(!empty($chat_avatar)): ?>
                            <img src="<?php echo htmlspecialchars($chat_avatar); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 2px solid #333;">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; background: #13131a; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; border: 2px solid #333;">
                                <?php echo ($chat_gender == 'f' ? '👧' : '👦'); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($unread_count > 0): ?>
                            <div style="position: absolute; top: -2px; right: -2px; background: #ff4444; color: white; border-radius: 10px; min-width: 20px; height: 20px; font-size: 0.75rem; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid #1c1c24; padding: 0 5px;">
                                <?php echo $unread_count; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="flex: 1; overflow: hidden;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="color: #fff; font-weight: bold; font-size: 1.05rem;"><?php echo htmlspecialchars($chat_username); ?></span>
                            <span style="font-size: 0.7rem; color: #666;"><?php echo !empty($last_date) ? date('H:i', strtotime($last_date)) : ''; ?></span>
                        </div>
                        <div style="font-size: 0.9rem; color: <?php echo $unread_count > 0 ? '#ddd' : '#888'; ?>; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo htmlspecialchars($last_msg); ?>
                        </div>
                    </div>
                </a>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align: center; color: #555; padding: 60px 20px; background: #1c1c24; border-radius: 20px; border: 1px dashed #333;">
                <i class="fa-solid fa-ghost" style="font-size: 3rem; margin-bottom: 15px; display: block; opacity: 0.3;"></i>
                <span style="font-size: 1.1rem;">Тут пока пусто...</span><br>
                <small>Напишите кому-нибудь в чате!</small>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php 
if (isset($db)) { @pg_close($db); }
echo "</div></body></html>"; 
?>
