<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Подключаем файл соединения с базой, если переменная $db еще не инициализирована
if (!isset($db)) {
    if (file_exists('db.php')) {
        include 'db.php';
    }
}

$header_my_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$notif_friends = 0; 
$notif_msgs = 0; 
$header_my_money = 0; 
$header_username = ""; 
$header_avatar_path = "";

if ($header_my_id > 0 && isset($db)) {
    // Обновляем статус активности текущего пользователя
    @pg_query_params($db, "UPDATE users SET last_active = NOW(), platform = 'web' WHERE id = $1", array($header_my_id));
    
    // Получаем актуальные данные залогиненного игрока для шапки (исправлено присваивание переменной)
    $res_user = @pg_query_params($db, "SELECT username, citymoney, avatar_path FROM users WHERE id = $1", array($header_my_id));
    if ($res_user && pg_num_rows($res_user) > 0) {
        $user_data = pg_fetch_assoc($res_user);
        $header_username = $user_data['username'] ?? '';
        $header_my_money = $user_data['citymoney'] ?? 0;
        $header_avatar_path = $user_data['avatar_path'] ?? '';
    }
    
    // Считаем входящие заявки в друзья
    $res_friends = @pg_query_params($db, "SELECT COUNT(*) as cnt FROM friends WHERE friend_id = $1 AND status = 'pending'", array($header_my_id));
    if ($res_friends) {
        $row_f = pg_fetch_assoc($res_friends);
        $notif_friends = $row_f['cnt'] ?? 0;
    }
    
    // Считаем непрочитанные сообщения
    $res_msgs = @pg_query_params($db, "SELECT COUNT(*) as cnt FROM messages WHERE receiver_id = $1 AND is_read = FALSE", array($header_my_id));
    if ($res_msgs) {
        $row_m = pg_fetch_assoc($res_msgs);
        $notif_msgs = $row_m['cnt'] ?? 0;
    }
}

// Получаем уникальных пользователей онлайн (активных за последние 5 минут) без дублей
$online_users = [];
if (isset($db)) {
    $res_online = @pg_query($db, "SELECT DISTINCT id, username, avatar_path, plot_coords, status_text FROM users WHERE last_active >= NOW() - INTERVAL '5 minutes' ORDER BY id DESC LIMIT 20");
    if ($res_online) {
        while ($row = pg_fetch_assoc($res_online)) {
            $online_users[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Love City Online</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary: #8e2de2; --secondary: #4a00e0; --accent: #00f2ff; --bg: #0b0b0e; --panel: #1c1c24; --gold: #ffd700; }
        
        .gift-premium { display: inline-block; position: relative; transition: 0.4s; filter: drop-shadow(0 0 8px rgba(0,0,0,0.8)); }
        @keyframes shine-anim {
            0% { filter: brightness(1) contrast(1.2); transform: scale(1); }
            50% { filter: brightness(1.5) contrast(1.5) drop-shadow(0 0 15px currentColor); transform: scale(1.05); }
            100% { filter: brightness(1) contrast(1.2); transform: scale(1); }
        }
        .gift-gold { background: linear-gradient(135deg, #bf953f 0%, #fcf6ba 45%, #b38728 70%, #fbf5b7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shine-anim 4s infinite ease-in-out; color: #ffcf4d; }
        .gift-diamond { background: linear-gradient(135deg, #e0f7fa 0%, #00e5ff 50%, #ffffff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shine-anim 5s infinite ease-in-out; color: #00f2ff; }
        .gift-ruby { background: linear-gradient(135deg, #ff0055 0%, #ff73a1 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shine-anim 3s infinite ease-in-out; color: #ff0055; }
        .gift-royal { background: linear-gradient(135deg, #8e2de2 0%, #ff00f2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shine-anim 6s infinite ease-in-out; color: #8e2de2; }

        body { background: var(--bg); color: #efefef; font-family: 'Segoe UI', sans-serif; margin: 0; padding-bottom: 50px; }
        header { background: linear-gradient(135deg, var(--primary), var(--secondary)); padding: 20px 0; text-align: center; position: relative; }
        header h1 { margin: 0; font-size: 2.2rem; letter-spacing: 4px; color: #fff; }
        
        /* Профиль в шапке */
        .header-user-info { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); background: rgba(0, 0, 0, 0.3); padding: 5px 12px; border-radius: 20px; font-size: 0.9rem; color: #fff; display: flex; align-items: center; gap: 10px; border: 1px solid rgba(255,255,255,0.1); }
        .header-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--accent); }
        .header-avatar-placeholder { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; color: #fff; border: 1px solid var(--accent); }

        nav { background: #16161d; display: flex; justify-content: center; flex-wrap: wrap; padding: 10px; border-bottom: 1px solid #333; position: sticky; top: 0; z-index: 1000; }
        nav a { color: #fff; text-decoration: none; padding: 10px 15px; font-weight: bold; font-size: 0.8rem; border-radius: 5px; transition: 0.3s; position: relative; }
        nav a:hover { color: var(--accent); background: rgba(255,255,255,0.05); }
        .notif-badge { background: #ff4444; color: white; font-size: 0.6rem; padding: 2px 6px; border-radius: 10px; position: absolute; top: 0; right: 0; }
        .money-nav { color: var(--gold); padding: 10px 15px; font-weight: bold; font-size: 0.85rem; display: flex; align-items: center; gap: 5px; }
        
        /* Блок онлайн пользователей */
        .online-ticker-container { background: #121217; border-bottom: 1px solid #2a2a35; overflow: hidden; padding: 8px 15px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .online-title { font-size: 0.75rem; color: var(--accent); text-transform: uppercase; font-weight: bold; letter-spacing: 1px; border-right: 1px solid #333; padding-right: 15px; }
        .online-list { display: flex; gap: 10px; overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
        .online-list::-webkit-scrollbar { display: none; }
        
        .ticker-item { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.03); padding: 4px 12px; border-radius: 15px; cursor: pointer; border: 1px solid rgba(255,255,255,0.05); transition: 0.2s; }
        .ticker-item:hover { background: rgba(0,242,255,0.1); border-color: var(--accent); }
        .ticker-avatar { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }
        .ticker-placeholder { width: 24px; height: 24px; border-radius: 50%; background: #333; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #aaa; }
        .ticker-name { font-size: 0.85rem; color: #fff; font-weight: 600; }
        .online-dot { width: 7px; height: 7px; background: #00ff66; border-radius: 50%; box-shadow: 0 0 5px #00ff66; }

        /* Модальное окно игрока */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(5px); }
        .modal-content { background: var(--panel); border: 1px solid #333; border-radius: 15px; padding: 25px; width: 320px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position: relative; }
        .modal-close { position: absolute; top: 15px; right: 15px; background: none; border: none; color: #aaa; font-size: 1.2rem; cursor: pointer; }
        .modal-close:hover { color: #fff; }
        .modal-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--accent); }
        .modal-username { font-size: 1.2rem; font-weight: bold; margin-bottom: 5px; color: #fff; }
        .modal-status { font-size: 0.85rem; color: #aaa; margin-bottom: 20px; font-style: italic; }
        .modal-buttons { display: flex; flex-direction: column; gap: 10px; }
        .modal-btn { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border: none; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; transition: 0.2s; font-size: 0.9rem; }
        .modal-btn:hover { filter: brightness(1.2); }
        .modal-btn-alt { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); }
        .modal-btn-alt:hover { background: rgba(255,255,255,0.1); }

        .main-container { max-width: 1000px; margin: 20px auto; background: var(--panel); padding: 20px; border-radius: 15px; border: 1px solid #2a2a35; min-height: 400px; }

        @media(max-width: 600px) {
            .header-user-info { position: static; transform: none; margin-top: 10px; display: inline-flex; }
            header { padding: 15px 10px; }
        }
    </style>
</head>
<body>
<header>
    <h1><i class="fa-solid fa-city"></i> LOVE CITY</h1>
    <?php if (!empty($header_username)): ?>
        <div class="header-user-info">
            <?php if (!empty($header_avatar_path)): ?>
                <img src="<?php echo htmlspecialchars($header_avatar_path); ?>" class="header-avatar" alt="avatar">
            <?php else: ?>
                <div class="header-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($header_username); ?></span>
        </div>
    <?php endif; ?>
</header>

<nav>
    <a href="index.php">Главная</a>
    <a href="map.php">Карта</a>
    <a href="friends.php">Друзья <?php if($notif_friends > 0) echo "<span class='notif-badge'>$notif_friends</span>"; ?></a>
    <a href="users_list.php">Жители</a>
    <a href="inbox.php">Почта <?php if($notif_msgs > 0) echo "<span class='notif-badge' style='background:var(--accent);color:#000'>$notif_msgs</span>"; ?></a>
    <a href="chat.php">Чат</a>
    <a href="profile.php">Профиль</a>
    <?php if($header_my_id > 0): ?>
        <div class="money-nav"><i class="fa-solid fa-coins"></i> <?php echo number_format($header_my_money); ?> CM</div>
        <a href="logout.php" style="color:#ff4444;">Выход</a>
    <?php endif; ?>
</nav>

<!-- Панель онлайн пользователей без дублей -->
<?php if (!empty($online_users)): ?>
<div class="online-ticker-container">
    <div class="online-title"><i class="fa-solid fa-signal"></i> Онлайн:</div>
    <div class="online-list">
        <?php foreach ($online_users as $u):
            $u_id = (int)$u['id'];
            $u_name = htmlspecialchars($u['username']);
            $u_avatar = $u['avatar_path'];
            $u_coords = htmlspecialchars($u['plot_coords'] ?? '1-1');
            $u_status = htmlspecialchars($u['status_text'] ?? 'Житель Love City');
        ?>
            <div class="ticker-item" onclick="openUserModal(<?php echo $u_id; ?>, '<?php echo $u_name; ?>', '<?php echo $u_avatar; ?>', '<?php echo $u_coords; ?>', '<?php echo $u_status; ?>')">
                <div class="online-dot"></div>
                <?php if (!empty($u_avatar)): ?>
                    <img src="<?php echo htmlspecialchars($u_avatar); ?>" class="ticker-avatar" alt="">
                <?php else: ?>
                    <div class="ticker-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <span class="ticker-name"><?php echo $u_name; ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Модальное окно игрока -->
<div id="userModal" class="modal-overlay" onclick="closeUserModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="document.getElementById('userModal').style.display='none'">&times;</button>
        <div id="modalAvatarContainer"></div>
        <div id="modalUsername" class="modal-username">Имя</div>
        <div id="modalStatus" class="modal-status">Статус</div>
        <div class="modal-buttons">
            <a id="modalProfileBtn" href="#" class="modal-btn"><i class="fa-solid fa-id-card"></i> Посмотреть профиль</a>
            <button onclick="sendFriendReq()" class="modal-btn modal-btn-alt"><i class="fa-solid fa-user-plus"></i> Добавить в друзья</button>
            <a id="modalVisitBtn" href="#" class="modal-btn modal-btn-alt"><i class="fa-solid fa-house"></i> Зайти в гости</a>
            <a id="modalMsgBtn" href="#" class="modal-btn modal-btn-alt"><i class="fa-solid fa-envelope"></i> Отправить сообщение</a>
        </div>
    </div>
</div>

<script>
let selectedUserId = 0;

function openUserModal(id, username, avatar, coords, status) {
    selectedUserId = id;
    document.getElementById('modalUsername').innerText = username;
    document.getElementById('modalStatus').innerText = status;
    
    let avContainer = document.getElementById('modalAvatarContainer');
    if (avatar && avatar.trim() !== '') {
        avContainer.innerHTML = `<img src="${avatar}" class="modal-avatar" alt="">`;
    } else {
        avContainer.innerHTML = `<div class="modal-avatar" style="background:#333; display:flex; align-items:center; justify-content:center; margin: 0 auto 10px; font-size:2rem; color:#aaa;"><i class="fa-solid fa-user"></i></div>`;
    }
    
    document.getElementById('modalProfileBtn').href = `profile.php?id=${id}`;
    document.getElementById('modalVisitBtn').href = `map.php?room=${coords}`;
    document.getElementById('modalMsgBtn').href = `inbox.php?to=${id}`;
    
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal(e) {
    if (e.target.id === 'userModal') {
        document.getElementById('userModal').style.display = 'none';
    }
}

function sendFriendReq() {
    if (!selectedUserId) return;
    fetch('friends.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_request&target_id=' + selectedUserId
    })
    .then(res => res.text())
    .then(data => {
        alert('Запрос в друзья отправлен!');
        document.getElementById('userModal').style.display = 'none';
    })
    .catch(err => {
        alert('Ошибка отправки запроса');
    });
}
</script>

<div class="main-container">
