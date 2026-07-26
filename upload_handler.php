<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Проверка авторизации
if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    die("Доступ запрещен. Пожалуйста, войдите в аккаунт."); 
}

$user_id = (int)$_SESSION['user_id'];

// 1. ОПРЕДЕЛЕНИЕ ПУТЕЙ (Используем относительные пути от корня или абсолютные через __DIR__)
$basePath = __DIR__ . DIRECTORY_SEPARATOR;
$uploadsFolder = 'uploads';
$avatarsFolder = 'uploads/avatars';
$photosFolder = 'uploads/photos';

// 2. АВТО-СОЗДАНИЕ ПАПОК (Если их нет)
$directories = [$uploadsFolder, $avatarsFolder, $photosFolder];
foreach ($directories as $dir) {
    if (!file_exists($basePath . $dir)) {
        if (!mkdir($basePath . $dir, 0777, true)) {
            if (isset($db)) { @pg_close($db); }
            die("Критическая ошибка: Не удалось создать папку $dir. Проверьте права доступа в корне сайта.");
        }
    }
}

/**
 * Универсальная функция загрузки
 */
function processUpload($file, $subDir, $prefix) {
    global $basePath, $user_id;

    // Проверка на ошибки PHP при загрузке
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ["error" => "Ошибка загрузки файла (код: " . $file['error'] . ")."];
    }

    $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Проверка расширения
    if (!in_array($extension, $allowedTypes)) {
        return ["error" => "Недопустимый формат. Разрешены: " . implode(', ', $allowedTypes)];
    }

    // Проверка, что это реально картинка
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        return ["error" => "Файл не является изображением."];
    }

    // Ограничение размера (5 МБ)
    if ($file["size"] > 5 * 1024 * 1024) {
        return ["error" => "Файл слишком большой. Максимальный размер — 5 МБ."];
    }

    // Генерация уникального имени
    $newFileName = $prefix . "_u" . $user_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $extension;
    
    // Путь для сервера (куда кладем физически)
    $destination = $basePath . $subDir . DIRECTORY_SEPARATOR . $newFileName;
    
    // Путь для базы данных (как будем обращаться через браузер)
    $dbPath = $subDir . '/' . $newFileName;

    if (move_uploaded_file($file["tmp_name"], $destination)) {
        return ["success" => $dbPath];
    } else {
        return ["error" => "Не удалось сохранить файл. Проверьте права доступа для папки " . $subDir];
    }
}

// --- ЛОГИКА ОБРАБОТКИ ---

// Аватар
if (isset($_FILES['avatar_file'])) {
    $res = processUpload($_FILES['avatar_file'], $avatarsFolder, "avatar");
    if (isset($res['success'])) {
        $path = $res['success'];
        pg_query_params($db, "UPDATE users SET avatar_path = $1 WHERE id = $2", array($path, $user_id));
        if (isset($db)) { @pg_close($db); }
        header("Location: profile.php?status=avatar_ok");
        exit;
    } else {
        if (isset($db)) { @pg_close($db); }
        die("Ошибка при смене аватара: " . $res['error']);
    }
}

// Фото в галерею
if (isset($_FILES['photo_file'])) {
    $res = processUpload($_FILES['photo_file'], $photosFolder, "photo");
    if (isset($res['success'])) {
        $path = $res['success'];
        pg_query_params($db, "INSERT INTO user_photos (user_id, photo_path) VALUES ($1, $2)", array($user_id, $path));
        if (isset($db)) { @pg_close($db); }
        header("Location: profile.php?status=photo_ok#gallery");
        exit;
    } else {
        if (isset($db)) { @pg_close($db); }
        die("Ошибка при добавлении в галерею: " . $res['error']);
    }
}

if (isset($db)) { @pg_close($db); }
header("Location: profile.php");
exit;
