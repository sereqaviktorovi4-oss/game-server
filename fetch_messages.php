<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$my_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Оптимизированный запрос: берем последние 40 сообщений и сразу разворачиваем их 
// в правильном хронологическом порядке для ленты чата (старые сверху, новые снизу)
$sql = "
    SELECT * FROM (
        SELECT c.*, u.id as u_id, u.username as real_username
        FROM chat c 
        LEFT JOIN users u ON c.user_id = u.id 
        ORDER BY c.id DESC LIMIT 40
    ) sub 
    ORDER BY sub.id ASC
";

$result = pg_query($db, $sql);
$rows = [];

if ($result) {
    while($m = pg_fetch_assoc($result)) {
        // Если имя в чате вдруг пустое, берем из таблицы users или ставим 'Гость'
        $display_username = !empty($m['username']) ? $m['username'] : ($m['real_username'] ?? 'Гость');
        
        // Надежная проверка «своего» сообщения по уникальному ID пользователя, а не по тексту ника
        $message_user_id = isset($m['u_id']) ? (int)$m['u_id'] : (isset($m['user_id']) ? (int)$m['user_id'] : 0);
        $is_me = ($my_id > 0 && $message_user_id === $my_id);
        
        // Прямое управление позицией (flex-end - право, flex-start - лево)
        $side = $is_me ? 'flex-end' : 'flex-start';
        $bg = $is_me ? '#2d1b4d' : '#1c1c24'; // Фиолетовый для тебя, темный для других
        $border = $is_me ? 'border-right: 3px solid var(--accent); border-bottom-right-radius: 2px;' : 'border-left: 3px solid var(--primary); border-bottom-left-radius: 2px;';
        $nick_color = $is_me ? 'var(--accent)' : 'var(--primary)';
        
        $time = isset($m['created_at']) ? date('H:i', strtotime($m['created_at'])) : '';
        $safe_u_id = (int)$message_user_id;

        // Используем структуру в точности как в привате
        $html = "
        <div class='msg-row' style='display: flex; flex-direction: column; width: 100%; align-items: {$side}; margin-bottom: 10px;'>
            <div class='chat-nick' data-id='{$safe_u_id}' style='color: {$nick_color}; cursor: pointer; font-weight: bold; font-size: 0.75rem; margin-bottom: 2px; padding: 0 10px;'>
                " . htmlspecialchars($display_username) . "
            </div>
            <div class='bubble' style='max-width: 85%; padding: 10px 14px; border-radius: 18px; font-size: 0.95rem; background: {$bg}; color: #fff; {$border} box-shadow: 0 2px 5px rgba(0,0,0,0.3);'>
                " . htmlspecialchars($m['message']) . "
                <span style='font-size: 0.6rem; opacity: 0.4; display: block; margin-top: 4px; text-align: right;'>{$time}</span>
            </div>
        </div>";
        
        $rows[] = $html;
    }
}

// Выводим собранные сообщения без лишнего array_reverse, так как база уже отдала их в правильном порядке
echo implode('', $rows);

// Обязательно закрываем соединение с базой для стабильности хостинга
if (isset($db)) { @pg_close($db); }
?>
