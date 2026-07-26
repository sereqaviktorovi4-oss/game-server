if ($action == 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Точно такой же запрос, как на сайте (без всяких LOWER)
    $result = pg_query_params($db, "SELECT * FROM users WHERE username = $1", array($username));
    
    if ($result && pg_num_rows($result) > 0) {
        $user = pg_fetch_assoc($result);
        
        // Точно такая же проверка пароля через password_verify, как на сайте
        if (password_verify($password, $user['password'])) {
            
            // Обновляем статус в базе: зашел из игры (game)
            pg_query_params($db, "UPDATE users SET platform = 'game', last_seen = NOW() WHERE id = $1", array((int)$user['id']));
            
            $px = isset($user['pos_x']) ? floatval($user['pos_x']) : 0.0;
            $py = isset($user['pos_y']) ? floatval($user['pos_y']) : 0.5;
            $pz = isset($user['pos_z']) ? floatval($user['pos_z']) : 0.0;
            
            $raw_dist = trim((string)($user['plot_coords'] ?? ''));
            $dist = (empty($raw_dist) || $raw_dist === '0' || $raw_dist === '0,0,0') ? '1-1' : $raw_dist;

            header('Content-Type: text/plain; charset=utf-8');
            echo "success:" . $user['username'] . ":" . $user['id'] . ":" . $px . ":" . $py . ":" . $pz . ":" . $dist . ":" . $dist;
            exit;
        } else { 
            header('Content-Type: text/plain; charset=utf-8');
            echo "wrong_password"; 
            exit;
        }
    } else { 
        header('Content-Type: text/plain; charset=utf-8');
        echo "not_found"; 
        exit;
    }
}
