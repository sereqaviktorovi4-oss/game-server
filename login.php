<?php 
// 1. ЗАПУСК СЕССИИ — строго первая строка
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'db.php'; 

// Обрабатываем POST-запрос до вывода любого контента
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Ищем пользователя с помощью параметризованного запроса
    $result = pg_query_params($db, "SELECT * FROM users WHERE username = $1", array($username));
    
    if ($result && pg_num_rows($result) > 0) {
        $user = pg_fetch_assoc($result);
        
        // Проверка пароля
        if (password_verify($password, $user['password'])) {
            // Сохраняем данные в сессию
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['gender'] = $user['gender'] ?? 'm';
            
            // СОХРАНЯЕМ КООРДИНАТЫ УЧАСТКА В СЕССИЮ
            $_SESSION['plot_coords'] = !empty($user['plot_coords']) ? $user['plot_coords'] : "";
            
            // Обновляем статус в базе: зашел через сайт (web), используя параметризованный запрос
            pg_query_params($db, "UPDATE users SET platform = 'web', last_seen = NOW() WHERE id = $1", array((int)$user['id']));
            
            // Закрываем соединение перед редиректом
            if (isset($db)) { @pg_close($db); }

            // Мгновенный редирект на главную
            header("Location: index.php"); 
            exit;
        } else {
            $error = "Неверный пароль!";
        }
    } else {
        $error = "Пользователь не найден!";
    }
}

// Теперь подключаем визуальную часть
if (file_exists('header.php')) {
    include 'header.php'; 
}
?>

<div style="max-width: 400px; margin: 50px auto; text-align: center; background: #1c1c24; padding: 30px; border-radius: 15px; border: 1px solid #333; font-family: sans-serif;">
    <h2 style="color: #00f2ff; margin-bottom: 20px;">Вход в Love City</h2>
    
    <?php if (isset($error)): ?>
        <p style="color: #ff4d4d; background: rgba(255, 77, 77, 0.1); padding: 10px; border-radius: 5px;"><?php echo $error; ?></p>
    <?php endif; ?>

    <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
        <input type="text" name="username" placeholder="Ваш никнейм" required 
               style="padding: 12px; border-radius: 8px; border: 1px solid #444; background: #0b0b0e; color: #fff; outline: none;">
        
        <input type="password" name="password" placeholder="Ваш пароль" required 
               style="padding: 12px; border-radius: 8px; border: 1px solid #444; background: #0b0b0e; color: #fff; outline: none;">
        
        <button type="submit" style="padding: 15px; background: linear-gradient(45deg, #00f2ff, #0072ff); border: none; border-radius: 8px; color: #fff; font-weight: bold; cursor: pointer; transition: 0.3s;">
            ВОЙТИ В ГОРОД
        </button>
    </form>
    
    <p style="margin-top: 20px;">
        <a href="register.php" style="color: #ff00ff; text-decoration: none; font-size: 0.9rem; border-bottom: 1px solid #ff00ff;">Еще не зарегистрированы?</a>
    </p>
</div>

<?php 
if (isset($db)) { @pg_close($db); }
if (file_exists('footer.php')) {
    include 'footer.php'; 
}
?>
