<?php
include 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'header.php'; 

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit; 
}
$user_id = (int)$_SESSION['user_id'];
$message = "";

// --- ЛОГИКА СОХРАНЕНИЯ ТЕКСТОВЫХ ДАННЫХ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_texts'])) {
    $new_email = trim($_POST['email']);
    $new_year = intval($_POST['year']);
    $new_status = trim($_POST['status_text']);

    $sql = "UPDATE users SET email = $1, birth_year = $2, status_text = $3 WHERE id = $4";
    $res = pg_query_params($db, $sql, array($new_email, $new_year, $new_status, $user_id));

    if ($res) {
        $message = "<div class='alert-success'>✅ Информация обновлена!</div>";
    } else {
        $message = "<div class='alert-error'>❌ Ошибка при обновлении данных!</div>";
    }
}

// --- ЛОГИКА ПЛАТНОЙ СМЕНЫ НИКА (100 СМ) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_nick'])) {
    $new_nick = trim($_POST['new_username']);
    $price = 100;

    // Получаем баланс пользователя
    $res_user = pg_query_params($db, "SELECT citymoney FROM users WHERE id = $1", array($user_id));
    $user_data = pg_fetch_assoc($res_user);
    
    if (strlen($new_nick) < 3) {
        $message = "<div class='alert-error'>❌ Ник слишком короткий!</div>";
    } elseif ($user_data['citymoney'] < $price) {
        $message = "<div class='alert-error'>❌ Недостаточно СМ (нужно 100)!</div>";
    } else {
        // Проверяем, не занят ли ник другим пользователем
        $check_nick = pg_query_params($db, "SELECT id FROM users WHERE username = $1 AND id != $2", array($new_nick, $user_id));
        
        if (pg_num_rows($check_nick) > 0) {
            $message = "<div class='alert-error'>❌ Этот ник уже занят!</div>";
        } else {
            $sql = "UPDATE users SET username = $1, citymoney = citymoney - $2 WHERE id = $3";
            $res_update = pg_query_params($db, $sql, array($new_nick, $price, $user_id));
            
            if ($res_update) {
                $_SESSION['username'] = $new_nick; // Обновляем в сессии
                $message = "<div class='alert-success'>✅ Ник успешно изменен на " . htmlspecialchars($new_nick) . "!</div>";
            } else {
                $message = "<div class='alert-error'>❌ Ошибка смены ника!</div>";
            }
        }
    }
}

// Загружаем актуальные данные пользователя
$res_profile = pg_query_params($db, "SELECT * FROM users WHERE id = $1", array($user_id));
$user = pg_fetch_assoc($res_profile);
?>

<style>
    .edit-container { max-width: 600px; margin: 20px auto; padding: 0 15px; }
    .edit-card { background: #252530; border-radius: 20px; border: 1px solid #333; padding: 25px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
    .edit-card h3 { margin-top: 0; font-size: 1.1rem; color: #fff; display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
    .edit-card h3 i { color: var(--accent); }
    
    label { display: block; font-size: 0.8rem; color: #888; margin-bottom: 8px; margin-top: 15px; text-transform: uppercase; letter-spacing: 1px; }
    input[type="text"], input[type="email"], input[type="number"], input[type="file"] {
        width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid #444; background: #13131a; color: #fff; outline: none; transition: 0.3s;
    }
    input:focus { border-color: var(--accent); box-shadow: 0 0 10px rgba(142,45,226,0.2); }
    
    .alert-success { background: rgba(0, 255, 0, 0.1); color: #00ff00; padding: 15px; border-radius: 12px; text-align: center; margin-bottom: 20px; border: 1px solid rgba(0, 255, 0, 0.2); }
    .alert-error { background: rgba(255, 0, 0, 0.1); color: #ff4444; padding: 15px; border-radius: 12px; text-align: center; margin-bottom: 20px; border: 1px solid rgba(255, 0, 0, 0.2); }
    
    .price-tag { background: #1a1a24; color: gold; padding: 4px 8px; border-radius: 6px; font-size: 0.7rem; border: 1px solid gold; }
</style>

<div class="edit-container">
    <h2 style="color: #fff; text-align: center; margin-bottom: 30px;">⚙️ Настройки персонажа</h2>
    
    <?php echo $message; ?>

    <div class="edit-card">
        <h3><i class="fa-solid fa-camera-retro"></i> Внешний вид</h3>
        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
            <img src="<?php echo !empty($user['avatar_path']) ? htmlspecialchars($user['avatar_path']) : 'img/default_avatar.png'; ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent);">
            <div style="font-size: 0.8rem; color: #888;">Выберите новое фото для вашего профиля.</div>
        </div>
        <form action="upload_handler.php" method="POST" enctype="multipart/form-data">
            <input type="file" name="avatar_file" required>
            <button type="submit" class="btn-action" style="margin-top: 15px; width: 100%;">ОБНОВИТЬ ФОТО</button>
        </form>
    </div>

    <div class="edit-card" style="border-color: gold;">
        <h3><i class="fa-solid fa-id-card" style="color: gold;"></i> Игровое имя <span class="price-tag">100 СМ</span></h3>
        <form method="POST">
            <label>Ваш текущий ник:</label>
            <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled style="opacity: 0.5; background: #000;">
            
            <label>Новый никнейм:</label>
            <input type="text" name="new_username" placeholder="Введите новое имя..." required>
            <button type="submit" name="change_nick" class="btn-action" style="margin-top: 15px; width: 100%; background: linear-gradient(45deg, #bf953f, #fcf6ba); color: #000; font-weight: bold;">ИЗМЕНИТЬ ЗА 100 СМ</button>
        </form>
    </div>

    <div class="edit-card">
        <h3><i class="fa-solid fa-user-pen"></i> Информация</h3>
        <form method="POST">
            <label>Девиз / Статус:</label>
            <input type="text" name="status_text" value="<?php echo htmlspecialchars($user['status_text'] ?? ''); ?>" maxlength="100">

            <label>Электронная почта:</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            
            <label>Год рождения:</label>
            <input type="number" name="year" value="<?php echo (int)$user['birth_year']; ?>" required>
            
            <button type="submit" name="save_texts" class="btn-action" style="width: 100%; margin-top: 20px;">СОХРАНИТЬ ИЗМЕНЕНИЯ</button>
        </form>
    </div>

    <div class="edit-card" style="border-color: #ff00ff;">
        <h3><i class="fa-solid fa-images" style="color: #ff00ff;"></i> Галерея</h3>
        <form action="upload_handler.php" method="POST" enctype="multipart/form-data">
            <p style="font-size: 0.8rem; color: #888; margin-bottom: 15px;">Добавьте новое фото в свою личную галерею.</p>
            <input type="file" name="photo_file" required>
            <button type="submit" class="btn-action" style="margin-top: 15px; width: 100%; background: #ff00ff;">ДОБАВИТЬ В ГАЛЕРЕЮ</button>
        </form>
    </div>

    <div style="text-align: center; margin-bottom: 40px;">
        <a href="profile.php" style="color: #555; text-decoration: none; font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Назад в профиль</a>
    </div>
</div>

<?php 
if (isset($db)) { @pg_close($db); }
echo "</div></body></html>"; 
?>
