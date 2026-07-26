<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php'; 

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==========================================
// 1. АВТОРИЗАЦИЯ ИГРОКА (LOGIN)
// ==========================================
if ($action == 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = pg_query_params($db, "SELECT * FROM users WHERE username = $1", array($username));
    if ($result && pg_num_rows($result) > 0) {
        $user = pg_fetch_assoc($result);
        if (password_verify($password, $user['password'])) {
            pg_query_params($db, "UPDATE users SET platform = 'game', last_seen = NOW() WHERE id = $1", array((int)$user['id']));
            
            $px = isset($user['pos_x']) ? floatval($user['pos_x']) : 0.0;
            $py = isset($user['pos_y']) ? floatval($user['pos_y']) : 0.0;
            $pz = isset($user['pos_z']) ? floatval($user['pos_z']) : 0.0;
            
            $raw_dist = trim((string)($user['plot_coords'] ?? ''));
            $dist = (empty($raw_dist) || $raw_dist === '0' || $raw_dist === '0,0,0') ? '1-1' : $raw_dist;

            echo "success:" . $user['username'] . ":" . $user['id'] . ":" . $px . ":" . $py . ":" . $pz . ":" . $dist . ":" . $dist;
        } else { 
            echo "wrong_password"; 
        }
    } else { 
        echo "not_found"; 
    }
}

// ==========================================
// 2. ПОЛУЧЕНИЕ ДАННЫХ ПРОФИЛЯ
// ==========================================
elseif ($action == 'get_profile') {
    $user_id = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    if ($user_id > 0) {
        $result = pg_query_params($db, "SELECT username, status_text, gender, plot_coords, money FROM users WHERE id = $1", array($user_id));
        if ($result && pg_num_rows($result) > 0) {
            $user = pg_fetch_assoc($result);
            
            $raw_plot = trim((string)($user['plot_coords'] ?? ''));
            $plot = (empty($raw_plot) || $raw_plot === '0' || $raw_plot === '0,0,0') ? "1-1" : $raw_plot;
            
            $response = [
                "status" => "success",
                "username" => $user['username'],
                "status_text" => !empty($user['status_text']) ? $user['status_text'] : "Я здесь новый житель!",
                "gender" => !empty($user['gender']) ? $user['gender'] : "m",
                "district_name" => "Love Sector A",
                "plot_coords" => $plot,
                "citymoney" => intval($user['money'] ?? 100)
            ];
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            if (isset($db)) { @pg_close($db); }
            exit;
        }
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "error", "message" => "user_not_found"], JSON_UNESCAPED_UNICODE);
}

// ==========================================
// 3. СОХРАНЕНИЕ ПОСТРОЙКИ
// ==========================================
elseif ($action == 'save_build') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    $z = floatval($_POST['z'] ?? 0);
    
    $build_type = $_POST['shape'] ?? $_POST['build_type'] ?? 'default';

    $query = "INSERT INTO builds (user_id, pos_x, pos_y, pos_z, build_type) VALUES ($1, $2, $3, $4, $5)";
    $res = pg_query_params($db, $query, array($user_id, $x, $y, $z, $build_type));
    
    if ($res) {
        echo "saved";
    } else {
        echo "error";
    }
}

// ==========================================
// 4. ЗАГРУЗКА ВСЕХ ПОСТРОЕК
// ==========================================
elseif ($action == 'load_all_builds') {
    $res = pg_query($db, "SELECT * FROM builds");
    $builds = [];
    if ($res) {
        while($row = pg_fetch_assoc($res)) {
            $builds[] = [
                "id" => $row['id'],
                "user_id" => $row['user_id'],
                "x" => floatval($row['pos_x']),
                "y" => floatval($row['pos_y']),
                "z" => floatval($row['pos_z']),
                "shape" => $row['build_type'] ?? 'default',
                "color" => $row['color_hex'] ?? '',
                "texture" => $row['texture_url'] ?? ''
            ];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($builds, JSON_UNESCAPED_UNICODE);
}

// ==========================================
// 5. ДАННЫЕ УЧАСТКА
// ==========================================
elseif ($action == 'get_land') {
    $name = $_GET['name'] ?? $_POST['name'] ?? '';
    $res = pg_query_params($db, "SELECT pos_x, pos_y, pos_z, plot_coords FROM users WHERE username = $1", array($name));
    
    if ($res && pg_num_rows($res) > 0) {
        $data = pg_fetch_assoc($res);
        $lx = (float)($data['pos_x'] ?? 0.0);
        $ly = (float)($data['pos_y'] ?? 0.0);
        $lz = (float)($data['pos_z'] ?? 0.0);
        
        $raw_dist = trim((string)($data['plot_coords'] ?? ''));
        $ldist = (empty($raw_dist) || $raw_dist === '0' || $raw_dist === '0,0,0') ? '1-1' : $raw_dist;

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "success",
            "x" => $lx,
            "y" => $ly,
            "z" => $lz,
            "district" => $ldist
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["status" => "error", "message" => "not_found"], JSON_UNESCAPED_UNICODE);
    }
}

// ==========================================
// 6. СПИСОК ЧАТОВ (ДИАЛОГОВ)
// ==========================================
elseif ($action == 'get_chats') {
    $user_id = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["status" => "error", "message" => "Invalid user"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Находим всех уникальных пользователей, с которыми были переписки
    $query = "SELECT DISTINCT 
                CASE WHEN sender_id = $1 THEN recipient_id ELSE sender_id END as partner_id 
              FROM messages 
              WHERE sender_id = $1 OR recipient_id = $1";
              
    $res = pg_query_params($db, $query, array($user_id));
    $chats = [];

    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $p_id = intval($row['partner_id']);
            // Узнаем имя партнера
            $u_res = pg_query_params($db, "SELECT username FROM users WHERE id = $1", array($p_id));
            $p_name = "Игрок #" . $p_id;
            if ($u_res && pg_num_rows($u_res) > 0) {
                $u_data = pg_fetch_assoc($u_res);
                $p_name = $u_data['username'];
            }

            $chats[] = [
                "partner_id" => $p_id,
                "partner_username" => $p_name
            ];
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "success", "chats" => $chats], JSON_UNESCAPED_UNICODE);
}

// ==========================================
// 7. ИСТОРИЯ СООБЩЕНИЙ С КОНКРЕТНЫМ ИГРОКОМ
// ==========================================
elseif ($action == 'get_history') {
    $user_id = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $partner_id = intval($_POST['partner_id'] ?? $_GET['partner_id'] ?? 0);

    if ($user_id <= 0 || $partner_id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["status" => "error", "message" => "Invalid users"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = "SELECT m.id, m.sender_id, m.recipient_id, m.message, m.created_at, u.username as sender_name 
              FROM messages m
              LEFT JOIN users u ON m.sender_id = u.id
              WHERE (m.sender_id = $1 AND m.recipient_id = $2) 
                 OR (m.sender_id = $2 AND m.recipient_id = $1)
              ORDER BY m.created_at ASC";

    $res = pg_query_params($db, $query, array($user_id, $partner_id));
    $messages = [];

    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $messages[] = [
                "id" => intval($row['id']),
                "sender_id" => intval($row['sender_id']),
                "sender" => $row['sender_name'] ?? "Житель",
                "message" => $row['message'],
                "time" => $row['created_at']
            ];
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "success", "messages" => $messages], JSON_UNESCAPED_UNICODE);
}

// ==========================================
// 8. ОТПРАВКА СООБЩЕНИЯ (ЧЕРЕЗ HTTP, если нужно)
// ==========================================
elseif ($action == 'private_chat') {
    $sender_id = intval($_POST['sender_id'] ?? 0);
    $recipient_id = intval($_POST['recipient_id'] ?? $_POST['target_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if ($sender_id > 0 && $recipient_id > 0 && !empty($message)) {
        $query = "INSERT INTO messages (sender_id, recipient_id, message, created_at) VALUES ($1, $2, $3, NOW())";
        $res = pg_query_params($db, $query, array($sender_id, $recipient_id, $message));

        header('Content-Type: application/json; charset=utf-8');
        if ($res) {
            echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "error", "message" => "db_insert_failed"], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "error", "message" => "invalid_params"], JSON_UNESCAPED_UNICODE);
}

// ==========================================
// 9. РЕДАКТИРОВАНИЕ СООБЩЕНИЯ
// ==========================================
elseif ($action == 'edit_message') {
    $message_id = intval($_POST['message_id'] ?? 0);
    $new_text = trim($_POST['message'] ?? '');

    if ($message_id > 0 && !empty($new_text)) {
        $query = "UPDATE messages SET message = $1 WHERE id = $2";
        $res = pg_query_params($db, $query, array($new_text, $message_id));

        header('Content-Type: application/json; charset=utf-8');
        if ($res) {
            echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "error", "message" => "db_update_failed"], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "error", "message" => "invalid_params"], JSON_UNESCAPED_UNICODE);
}

if (isset($db)) { @pg_close($db); }
?>
