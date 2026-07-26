<?php
header('Content-Type: text/plain; charset=utf-8');

$playlist_dir = __DIR__ . '/playlist/';
$output_file = __DIR__ . '/live_vibe.mp3';

// 1. Проверяем файлы
if (!is_dir($playlist_dir)) {
    mkdir($playlist_dir, 0777, true);
    die("Папка 'playlist' создана. Закинь туда MP3.");
}

$files = glob($playlist_dir . '*.mp3');
if (empty($files)) {
    die("Папка 'playlist' пуста!");
}

// 2. ЗАЩИТА ОТ РАЗДУВАНИЯ ДИСКА (Ротация файла)
// Если файл живого потока стал больше 20 Мегабайт, мы его очищаем
if (file_exists($output_file) && filesize($output_file) > 20 * 1024 * 1024) {
    file_put_contents($output_file, ''); 
    echo "Предупреждение: Буфер потока превысил 20Мб и был автоматически сброшен для экономии места.\n";
}

// 3. Выбираем случайный трек
$random_track = $files[array_rand($files)];
echo "Добавляем в поток трек: " . basename($random_track) . "\n";

// 4. Запись без спама в буфер
$input = fopen($random_track, 'rb');
$output = fopen($output_file, 'ab'); // 'ab' — дозапись

if ($input && $output) {
    while (!feof($input)) {
        // Читаем кусок и пишем, без var_dump() — теперь в браузере будет чисто
        fwrite($output, fread($input, 8192));
    }
    fclose($input);
    fclose($output);
    
    // Переводим байты в читаемые Мегабайты для отчета
    $current_size = round(filesize($output_file) / (1024 * 1024), 2);
    echo "Успешно дописано. Текущий вес радио-буфера на диске: " . $current_size . " Мб.\n";
} else {
    echo "Ошибка открытия файлов.";
}
?>
