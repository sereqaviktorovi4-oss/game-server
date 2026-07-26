<?php
// 1. Сессия должна быть САМОЙ первой строкой кода
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Подключаем базу (использует соединение PostgreSQL через $db)
include 'db.php'; 

// Проверяем, залогинен ли пользователь
$is_logged = isset($_SESSION['user_id']);

include 'header.php'; 

// Считаем жителей (безопасный запрос для PostgreSQL)
$total = 0;
$res = pg_query($db, "SELECT COUNT(id) as cnt FROM users");
if ($res) {
    $row = pg_fetch_assoc($res);
    $total = $row['cnt'];
}
?>

<div style="text-align: center; margin-bottom: 30px;">
    <h2 style="color: #00f2ff;">Добро пожаловать в Love City!</h2>
    <p style="font-size: 1.2rem;">Нас уже <span style="color: #ff00ff; font-weight: bold;"><?php echo $total; ?></span> жителей.</p>
</div>

<div class="reg-form-container" style="max-width: 500px; margin: 0 auto; text-align: center;">

    <?php if ($is_logged): ?>
        <div style="background: #252530; padding: 30px; border-radius: 15px; border: 1px solid #00f2ff;">
            <h3 style="color: #00f2ff;">Рады видеть тебя, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h3>
            <p>Твой город ждет тебя. Выбери действие:</p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                <a href="profile.php" class="btn-action" style="text-decoration:none; padding:10px; background:#333; color:white; border-radius:5px;">Профиль</a>
                <a href="map.php" class="btn-action" style="text-decoration:none; padding:10px; background:#333; color:white; border-radius:5px;">Карта</a>
                <a href="chat.php" class="btn-action" style="text-decoration:none; padding:10px; background:#333; color:white; border-radius:5px;">Чат</a>
                <a href="friends.php" class="btn-action" style="text-decoration:none; padding:10px; background:#333; color:white; border-radius:5px;">Друзья</a>
            </div>
            
            <div style="margin-top: 25px; border-top: 1px solid #444; padding-top: 15px;">
                <p style="font-size: 0.9rem; color: #aaa;">Статус: В сети через сайт</p>
                <a href="logout.php" style="color: #ff4444; font-size: 0.8rem; text-decoration: none;">Выйти из аккаунта</a>
            </div>
        </div>

    <?php else: ?>
        <form action="register.php" method="POST" style="background: #1a1a25; padding: 25px; border-radius: 15px; border: 1px solid #444;">
            <h3 style="margin-bottom: 20px; color: #ff00ff;">Регистрация в мегаполисе</h3>
            
            <input type="hidden" name="source" value="web">

            <div class="form-group" style="text-align: left; margin-bottom: 15px;">
                <label style="color: #00f2ff;">Никнейм:</label>
                <input type="text" name="username" placeholder="Твое имя в игре" required style="width:100%; padding:10px; border-radius:5px; background:#252530; border:1px solid #444; color:white;">
            </div>
            
            <div class="form-group" style="text-align: left; margin-bottom: 15px;">
                <label style="color: #00f2ff;">Пароль:</label>
                <input type="password" name="password" placeholder="Минимум 6 знаков" required style="width:100%; padding:10px; border-radius:5px; background:#252530; border:1px solid #444; color:white;">
            </div>

            <div class="form-group" style="text-align: left; margin-bottom: 15px;">
                <label style="color: #00f2ff;">E-mail:</label>
                <input type="email" name="email" placeholder="Для восстановления" required style="width:100%; padding:10px; border-radius:5px; background:#252530; border:1px solid #444; color:white;">
            </div>

            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <div style="flex: 1; text-align: left;">
                    <label style="color: #00f2ff;">Пол:</label>
                    <select name="gender" style="width:100%; padding:10px; border-radius:5px; background:#252530; border:1px solid #444; color:white;">
                        <option value="m">Мужчина</option>
                        <option value="f">Женщина</option>
                    </select>
                </div>
                <div style="flex: 1; text-align: left;">
                    <label style="color: #00f2ff;">Год рождения:</label>
                    <input type="number" name="year" value="2000" style="width:100%; padding:10px; border-radius:5px; background:#252530; border:1px solid #444; color:white;">
                </div>
            </div>
            
            <button type="submit" style="width: 100%; padding: 15px; background: #00f2ff; color: #000; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">СОЗДАТЬ АККАУНТ</button>
            <p style="margin-top: 15px; font-size: 0.9rem;">Уже есть аккаунт? <a href="login.php" style="color: #ff00ff;">Войти</a></p>
        </form>
    <?php endif; ?>

</div>

<p style='text-align:center; color:#555; margin-top:20px;'>Love City Online &copy; 2026</p>
<?php if (isset($db)) { @pg_close($db); } ?>
</body>
</html>
