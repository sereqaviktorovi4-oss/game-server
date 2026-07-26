<?php
session_start();
include 'db.php';

if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email'] ?? '');
    $raw_password = $_POST['password'];
    $gender = $_POST['gender'] ?? 'm';
    if ($gender !== 'm' && $gender !== 'f') {
        $gender = 'm';
    }
    $year = intval($_POST['year'] ?? 2000);
    $source = $_POST['source'] ?? 'game';

    // 1. Проверка существования пользователя (по никнейму или email) с использованием параметризованного запроса
    if (!empty($email)) {
        $check_query = "SELECT id FROM users WHERE username = $1 OR email = $2";
        $check_params = array($username, $email);
    } else {
        $check_query = "SELECT id FROM users WHERE username = $1";
        $check_params = array($username);
    }
    
    $result = pg_query_params($db, $check_query, $check_params);

    if ($result && pg_num_rows($result) > 0) {
        echo "error_exists"; 
    } else {
        // 2. Хеширование пароля (пароль извлекается стандартным способом, без экранирования хэша)
        $password_hash = password_hash($raw_password, PASSWORD_DEFAULT);
        
        // 3. Вставка с дефолтным участком '1-1' с использованием безопасных параметров
        $sql = "INSERT INTO users (username, password, email, gender, birth_year, money, citymoney, plot_coords) 
                VALUES ($1, $2, $3, $4, $5, 100, 100, '1-1') RETURNING id";
        
        $insert_result = pg_query_params($db, $sql, array($username, $password_hash, $email, $gender, $year));
        
        if ($insert_result) {
            if ($source == 'web') {
                $row = pg_fetch_assoc($insert_result);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $username;
                if (isset($db)) { @pg_close($db); }
                header("Location: index.php"); 
                exit;
            } else { 
                echo "success"; 
            }
        } else { 
            echo "error_db"; 
        }
    }
}

if (isset($db)) { @pg_close($db); }
?>
