<?php
// Вывод ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// --- ЛОГИКА ПОКУПКИ ---
if (isset($_GET['id'])) {
    $coords = pg_escape_string($db, $_GET['id']);
    
    // Получаем данные текущего пользователя
    $user_res = pg_query($db, "SELECT plot_coords, money FROM users WHERE id=$user_id");
    $user = ($user_res) ? pg_fetch_assoc($user_res) : null;
    
    if (!$user) {
        die("Пользователь не найден.");
    }
    
    // Проверка на занятость участка
    $check_busy = pg_query($db, "SELECT id FROM users WHERE plot_coords='$coords' AND id != $user_id");
    if ($check_busy && pg_num_rows($check_busy) > 0) {
        pg_close($db);
        die("Этот участок уже кем-то занят!");
    }

    // Первое заселение — бесплатно
    if (empty($user['plot_coords'])) {
        pg_query($db, "UPDATE users SET plot_coords='$coords', plot_name='Первый дом' WHERE id=$user_id");
        pg_close($db);
        header("Location: profile.php");
        exit;
    } else {
        // Покупка нового участка
        $price = 50; 
        $current_money = (int)$user['money'];

        if ($current_money >= $price) {
            // Списываем деньги и ставим новые координаты
            pg_query($db, "UPDATE users SET money = money - $price, plot_coords='$coords' WHERE id=$user_id");
            pg_close($db);
            header("Location: profile.php");
            exit;
        } else {
            // Если денег мало, выводим сколько есть в базе на самом деле
            pg_close($db);
            die("Недостаточно денег! В базе в колонке 'money' у вас: " . $current_money . ". Стоимость участка: " . $price);
        }
    }
} else {
    pg_close($db);
    header("Location: profile.php");
    exit;
}
?>
