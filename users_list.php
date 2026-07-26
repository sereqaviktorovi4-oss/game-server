<?php 
include 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'header.php'; 

if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php"); 
    exit; 
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Базовый запрос
$sql = "SELECT id, username, avatar_path, gender, status_text, last_active FROM users";
$params = [];

// Если есть поиск
if (!empty($search)) {
    // Проверяем, является ли запрос числом (для поиска по ID)
    if (ctype_digit($search)) {
        $search_id = (int)$search;
        $sql .= " WHERE id = $1 OR username ILIKE $2";
        $params = [$search_id, '%' . $search . '%'];
    } else {
        $sql .= " WHERE username ILIKE $1";
        $params = ['%' . $search . '%'];
    }
}

$sql .= " ORDER BY last_active DESC";
$res = !empty($params) ? pg_query_params($db, $sql, $params) : pg_query($db, $sql);

$men = [];
$women = [];

if ($res) {
    while($u = pg_fetch_assoc($res)) {
        if($u['gender'] == 'm') { $men[] = $u; } else { $women[] = $u; }
    }
}
?>

<style>
    .users-container { max-width: 1000px; margin: 20px auto; padding: 0 15px; }
    
    /* ИСПРАВЛЕННАЯ ПАНЕЛЬ ПОИСКА */
    .search-panel { 
        background: #252530; 
        padding: 8px 12px; 
        border-radius: 50px; 
        margin-bottom: 30px; 
        border: 1px solid #333; 
        display: flex; 
        gap: 8px; 
        align-items: center;
        flex-wrap: nowrap; /* Чтобы ничего не вылетало */
    }
    
    .search-input { 
        flex: 1; 
        background: transparent; 
        border: none; 
        color: #fff; 
        padding: 8px 5px; 
        outline: none; 
        font-size: 0.95rem;
        min-width: 0; /* Позволяет инпуту сжиматься на мобилках */
    }
    
    .search-btn { 
        background: var(--accent); 
        border: none; 
        color: #fff; 
        padding: 10px 20px; 
        border-radius: 40px; 
        cursor: pointer; 
        transition: 0.3s;
        white-space: nowrap;
        font-weight: bold;
        font-size: 0.9rem;
    }
    .search-btn:hover { opacity: 0.9; transform: scale(1.02); }

    /* СЕКЦИИ ПО ПОЛУ */
    .gender-section { margin-bottom: 40px; }
    .gender-title { 
        font-size: 1.3rem; margin-bottom: 20px; padding-bottom: 10px; 
        border-bottom: 2px solid #333; display: flex; align-items: center; gap: 10px;
    }
    .title-m { color: #00d4ff; border-color: #004a5a; }
    .title-w { color: #ff00ff; border-color: #5a005a; }

    /* СЕТКА ПОЛЬЗОВАТЕЛЕЙ */
    .users-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); 
        gap: 15px; 
    }

    .user-card { 
        background: #252530; border-radius: 15px; padding: 15px; text-align: center; 
        border: 1px solid #333; transition: 0.3s; text-decoration: none; color: inherit; position: relative;
    }
    .user-card:hover { transform: translateY(-5px); border-color: var(--accent); background: #1c1c24; }

    .user-ava { 
        width: 75px; height: 75px; border-radius: 50%; object-fit: cover; 
        margin-bottom: 10px; border: 3px solid #13131a; 
    }
    
    .status-online { 
        width: 12px; height: 12px; background: #00ff00; border-radius: 50%; 
        position: absolute; top: 15px; right: 15px; border: 2px solid #252530; 
        box-shadow: 0 0 10px #00ff00;
    }
    
    .user-name { font-weight: bold; color: #fff; margin-bottom: 5px; display: block; font-size: 0.9rem; }
    .user-id { font-size: 0.65rem; color: var(--accent); opacity: 0.7; display: block; margin-bottom: 5px; }
    .user-status { font-size: 0.7rem; color: #777; font-style: italic; display: block; height: 18px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

    /* АДАПТИВ ДЛЯ ТЕЛЕФОНОВ */
    @media (max-width: 480px) {
        .users-grid { grid-template-columns: 1fr 1fr; } /* По 2 карточки в ряд */
        .search-btn { padding: 8px 15px; font-size: 0.8rem; }
        .gender-title { font-size: 1.1rem; }
    }
</style>

<div class="users-container">
    <h2 style="text-align: center; margin-bottom: 25px; color: var(--accent);"><i class="fa-solid fa-city"></i> Жители города</h2>

    <form method="GET" class="search-panel">
        <i class="fa-solid fa-magnifying-glass" style="color: #555; margin-left: 5px;"></i>
        <input type="text" name="search" class="search-input" placeholder="Ник или ID..." value="<?php echo htmlspecialchars($search); ?>">
        
        <?php if(!empty($search)): ?>
            <a href="users_list.php" style="color: #777; text-decoration: none; font-size: 1.4rem; line-height: 1; margin-right: 5px;" title="Сбросить">&times;</a>
        <?php endif; ?>
        
        <button type="submit" class="search-btn">Найти</button>
    </form>

    <?php if(empty($men) && empty($women)): ?>
        <div style="text-align: center; padding: 60px 20px; color: #444;">
            <i class="fa-solid fa-user-slash" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.2;"></i>
            <p>Житель не найден в базе данных...</p>
        </div>
    <?php endif; ?>

    <?php if(!empty($men)): ?>
    <div class="gender-section">
        <h3 class="gender-title title-m"><i class="fa-solid fa-mars"></i> Мужчины (<?php echo count($men); ?>)</h3>
        <div class="users-grid">
            <?php foreach($men as $u): ?>
                <a href="profile.php?id=<?php echo (int)$u['id']; ?>" class="user-card">
                    <span class="user-id">ID: <?php echo (int)$u['id']; ?></span>
                    <?php if (!empty($u['last_active']) && $u['last_active'] > date('Y-m-d H:i:s', strtotime('-5 minutes'))): ?>
                        <div class="status-online" title="В сети"></div>
                    <?php endif; ?>
                    <img src="<?php echo !empty($u['avatar_path']) ? htmlspecialchars($u['avatar_path']) : 'img/default_m.png'; ?>" class="user-ava">
                    <span class="user-name"><?php echo htmlspecialchars($u['username']); ?></span>
                    <span class="user-status"><?php echo htmlspecialchars($u['status_text'] ?: 'Новый житель'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($women)): ?>
    <div class="gender-section">
        <h3 class="gender-title title-w"><i class="fa-solid fa-venus"></i> Женщины (<?php echo count($women); ?>)</h3>
        <div class="users-grid">
            <?php foreach($women as $u): ?>
                <a href="profile.php?id=<?php echo (int)$u['id']; ?>" class="user-card">
                    <span class="user-id">ID: <?php echo (int)$u['id']; ?></span>
                    <?php if (!empty($u['last_active']) && $u['last_active'] > date('Y-m-d H:i:s', strtotime('-5 minutes'))): ?>
                        <div class="status-online" title="В сети"></div>
                    <?php endif; ?>
                    <img src="<?php echo !empty($u['avatar_path']) ? htmlspecialchars($u['avatar_path']) : 'img/default_w.png'; ?>" class="user-ava">
                    <span class="user-name"><?php echo htmlspecialchars($u['username']); ?></span>
                    <span class="user-status"><?php echo htmlspecialchars($u['status_text'] ?: 'Жительница города'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php 
if (isset($db)) { @pg_close($db); }
echo "</div></body></html>"; 
?>
