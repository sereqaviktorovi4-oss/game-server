<?php
// Блокируем буферизацию, так как данные идут потоком
set_time_limit(0);
ignore_user_abort(true);

$target_file = __DIR__ . '/live_vibe.mp3';

// Открываем файл на перезапись/запись байтов
$output = fopen($target_file, 'wb');

if (!$output) {
    header("HTTP/1.1 500 Internal Server Error");
    die("Не удалось создать аудио-файл на сервере.");
}

// Читаем сырой поток данных, который шлет Sam Broadcaster
$input = fopen('php://input', 'rb');

if ($input) {
    // Перенаправляем входящие байты музыки напрямую в файл live_vibe.mp3
    while (!feof($input)) {
        $buffer = fread($input, 4096);
        if ($buffer === false) {
            break;
        }
        fwrite($output, $buffer);
        fflush($output); // Сбрасываем кэш на диск, чтобы Godot сразу видел новые байты
    }
    fclose($input);
}

fclose($output);
echo "Поток успешно завершен.";
?>
