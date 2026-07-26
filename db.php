<?php
// db.php — правильная инициализация без конфликтов сессий

// 1. Сначала настраиваем параметры сессии (ДО любых запусков и выводов)
$session_path = sys_get_temp_dir();
if (session_status() === PHP_SESSION_NONE) {
    @session_save_path($session_path);
    ini_set('session.cookie_lifetime', 86400);
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_path', '/');
}

// 2. Подключение к базе данных PostgreSQL
$host     = "dpg-d9grrh6pbkes73c77q80-a"; 
$port     = "5432";
$user     = "sereqa";
$pass     = "UieNKFeX7sMMEpYAE2z99ODEhFYRzeKT";
$dbname   = "lovesity";

$conn_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$pass}";
$db = pg_connect($conn_string);

if (!$db) {
    die("Ошибка подключения к БД PostgreSQL");
}

// 3. Безопасно запускаем сессию в самом конце файла, если она еще не активна
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
