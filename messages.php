<?php
// Вывод ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Вспомогательная функция безопасного парсинга JSON
function json_get_array($json_raw) {
    if (!$json_raw) return null;
    $decoded = json_decode($json_raw, true);
    return is_array($decoded) ? $decoded : null;
}

// =========================================================================
// ЧАСТЬ 1: API ДЛЯ GODOT (ЕСЛИ ПРИШЕЛ JSON)
// =========================================================================
$raw_input = file_get_contents('php://input');
$request = json_get_array($raw_input);

if ($request && isset($request['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $user_id = isset($request['user_id']) ? (int)$request['user_id'] : 0;
    $action = $request['action'];

    if ($user_id <= 0) {
        echo json_encode(["error" => "UNAUTHORIZED_API_USER"]);
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    // 1. Получить список активных диалогов пользователя
    if ($action === 'get_chats') {
        $sql = "SELECT DISTINCT 
                    CASE WHEN sender_id = $1 THEN receiver_id ELSE sender_id END AS partner_id 
                FROM messages 
                WHERE sender_id = $1 OR receiver_id = $1";
        $res = pg_query_params($db, $sql, array($user_id));
        
        $chats = [];
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $p_id = (int)$row['partner_id'];
                $u_res = pg_query_params($db, "SELECT username FROM users WHERE id = $1", array($p_id));
                if ($u_res && pg_num_rows($u_res) > 0) {
                    $u_data = pg_fetch_assoc($u_res);
                    $chats[] = [
                        "id" => $p_id,
                        "username" => $u_data['username']
                    ];
                }
            }
        }
        echo json_encode(["chats" => $chats]);
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    // 2. Получить историю переписки с конкретным пользователем
    if ($action === 'get_history') {
        $target_id = isset($request['target_id']) ? (int)$request['target_id'] : 0;
        
        $sql = "SELECT m.id, m.sender_id, m.message, m.created_at, m.is_vip, u.username AS sender_name 
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE (m.sender_id = $1 AND m.receiver_id = $2)
                   OR (m.sender_id = $2 AND m.receiver_id = $1)
                ORDER BY m.id ASC LIMIT 100";
                
        $res = pg_query_params($db, $sql, array($user_id, $target_id));
        $messages = [];
        
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $text = $row['message'];
                if ((int)$row['is_vip'] === 1) {
                    $text = "[color=gold][b]👑 " . $text . "[/b][/color]";
                }
                
                $messages[] = [
                    "id" => (int)$row['id'],
                    "sender_id" => (int)$row['sender_id'],
                    "sender" => $row['sender_name'],
                    "text" => $text,
                    "time" => date("H:i", strtotime($row['created_at']))
                ];
            }
        }
        echo json_encode(["messages" => $messages]);
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    // 3. Отправить сообщение
    if ($action === 'send_message') {
        $target_id = isset($request['target_id']) ? (int)$request['target_id'] : 0;
        $message = isset($request['message']) ? trim($request['message']) : '';
        $is_vip = isset($request['is_vip']) ? (int)$request['is_vip'] : 0;

        if ($target_id <= 0 || $message === '') {
            echo json_encode(["error" => "EMPTY_DATA"]);
            if (isset($db)) { @pg_close($db); }
            exit;
        }

        if ($is_vip === 1) {
            $user_res = pg_query_params($db, "SELECT citymoney FROM users WHERE id = $1", array($user_id));
            $user_data = ($user_res) ? pg_fetch_assoc($user_res) : null;
            if (!$user_data || (int)$user_data['citymoney'] < 10) {
                echo json_encode(["error" => "ERROR_MONEY"]);
                if (isset($db)) { @pg_close($db); }
                exit;
            }
            pg_query_params($db, "UPDATE users SET citymoney = citymoney - 10 WHERE id = $1", array($user_id));
        }

        $insert = "INSERT INTO messages (sender_id, receiver_id, message, is_vip, created_at) 
                   VALUES ($1, $2, $3, $4, NOW()) RETURNING id";
        
        $res = pg_query_params($db, $insert, array($user_id, $target_id, $message, $is_vip));
        if ($res) {
            $row = pg_fetch_assoc($res);
            echo json_encode(["status" => "OK", "message_id" => (int)$row['id']]);
        } else {
            echo json_encode(["error" => "DB_ERROR"]);
        }
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    // 4. Редактировать сообщение
    if ($action === 'edit_message') {
        $message_id = isset($request['message_id']) ? (int)$request['message_id'] : 0;
        $new_text = isset($request['message']) ? trim($request['message']) : '';

        if ($message_id <= 0 || $new_text === '') {
            echo json_encode(["error" => "EMPTY_DATA"]);
            if (isset($db)) { @pg_close($db); }
            exit;
        }

        $update = "UPDATE messages SET message = $1 WHERE id = $2 AND sender_id = $3";
        $res = pg_query_params($db, $update, array($new_text, $message_id, $user_id));
        if ($res) {
            if (pg_affected_rows($res) > 0) {
                echo json_encode(["status" => "OK"]);
            } else {
                echo json_encode(["error" => "ACCESS_DENIED_OR_NO_CHANGES"]);
            }
        } else {
            echo json_encode(["error" => "DB_ERROR"]);
        }
        if (isset($db)) { @pg_close($db); }
        exit;
    }

    // 5. Удалить сообщение
    if ($action === 'delete_message') {
        $message_id = isset($request['message_id']) ? (int)$request['message_id'] : 0;

        if ($message_id <= 0) {
            echo json_encode(["error" => "EMPTY_DATA"]);
            if (isset($db)) { @pg_close($db); }
            exit;
        }

        $delete = "DELETE FROM messages WHERE id = $1 AND sender_id = $2";
        $res = pg_query_params($db, $delete, array($message_id, $user_id));
        if ($res) {
            if (pg_affected_rows($res) > 0) {
                echo json_encode(["status" => "OK"]);
            } else {
                echo json_encode(["error" => "ACCESS_DENIED"]);
            }
        } else {
            echo json_encode(["error" => "DB_ERROR"]);
        }
        if (isset($db)) { @pg_close($db); }
        exit;
    }
}

// =========================================================================
// ЧАСТЬ 2: ОРИГИНАЛЬНАЯ ВЕБ-ВЕРСИЯ (HTML / JS ЧАТА)
// =========================================================================
if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php"); 
    exit; 
}

$my_id = (int)$_SESSION['user_id'];
$to_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;

if ($to_id <= 0) {
    include 'header.php';
    echo "<div style='color:#fff; padding:20px; text-align:center;'>Выберите получателя в меню чатов.</div>";
    if (isset($db)) { @pg_close($db); }
    echo "</div></body></html>";
    exit;
}

$res = pg_query_params($db, "SELECT username, avatar_path, gender FROM users WHERE id = $1", array($to_id));
$partner = ($res) ? pg_fetch_assoc($res) : null;

$my_res = pg_query_params($db, "SELECT citymoney FROM users WHERE id = $1", array($my_id));
$my_data = ($my_res) ? pg_fetch_assoc($my_res) : null;

if (!$partner) { 
    include 'header.php';
    echo "<div style='color:#fff; padding:20px; text-align:center;'>Житель не найден.</div>"; 
    if (isset($db)) { @pg_close($db); }
    echo "</div></body></html>";
    exit; 
}

include 'header.php'; 
?>

<div style="max-width: 600px; margin: 10px auto; background: #13131a; border-radius: 20px; border: 1px solid #2a2a35; overflow: hidden; display: flex; flex-direction: column; height: 85vh;">
    
    <div style="background: #1c1c24; padding: 12px 15px; border-bottom: 1px solid #333; display: flex; align-items: center; gap: 12px;">
        <a href="chat.php" style="color: #555;"><i class="fa-solid fa-chevron-left"></i></a>
        <a href="profile.php?id=<?php echo $to_id; ?>">
            <?php if(!empty($partner['avatar_path'])): ?>
                <img src="<?php echo htmlspecialchars($partner['avatar_path']); ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 1px solid var(--accent);">
            <?php else: ?>
                <div style="width: 35px; height: 35px; background: #252530; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <?php echo ($partner['gender'] == 'm' ? '👦' : '👧'); ?>
                </div>
            <?php endif; ?>
        </a>
        <div style="flex: 1;">
            <h4 style="margin: 0; color: #fff; font-size: 0.9rem;"><?php echo htmlspecialchars($partner['username']); ?></h4>
            <span style="font-size: 0.65rem; color: #00d4ff;">Баланс: <?php echo number_format($my_data['citymoney'] ?? 0); ?> СМ</span>
        </div>
    </div>

    <div id="msgBox" style="flex: 1; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 10px; background: #0f0f13;">
        <div style="text-align: center; color: #444; margin-top: 50px;"><i class="fa-solid fa-spinner fa-spin"></i></div>
    </div>

    <form id="privForm" style="background: #1c1c24; padding: 10px; display: flex; flex-direction: column; gap: 8px; border-top: 1px solid #333;">
        <div style="display: flex; gap: 8px; align-items: center;">
            <input type="text" id="mInput" placeholder="Написать жителю..." required autocomplete="off" 
                   style="flex: 1; padding: 12px; border-radius: 25px; border: 1px solid #333; background: #0f0f13; color: #fff; outline: none;">
            
            <label style="cursor: pointer; background: #252530; padding: 10px; border-radius: 50%; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; border: 1px solid #444;" title="Золотое сообщение (10 СМ)">
                <input type="checkbox" id="vipToggle" style="display: none;">
                <i id="vipIcon" class="fa-solid fa-crown" style="color: #555; transition: 0.3s;"></i>
            </label>

            <button type="submit" class="btn-action" style="width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
        <div id="vipNotice" style="font-size: 0.65rem; color: #777; padding-left: 15px; display: none;">Отправка золотого сообщения: <span style="color: gold;">-10 СМ</span></div>
    </form>
</div>

<style>
    .msg-row { display: flex; flex-direction: column; max-width: 85%; margin-bottom: 4px; }
    .sent { align-self: flex-end; }
    .rcvd { align-self: flex-start; }
    .bubble { padding: 10px 14px; border-radius: 18px; font-size: 0.95rem; position: relative; }
    .sent .bubble { background: #2d1b4d; color: #fff; border-right: 3px solid var(--accent); border-bottom-right-radius: 2px; }
    .rcvd .bubble { background: #1c1c24; color: #eee; border-left: 3px solid var(--primary); border-bottom-left-radius: 2px; }
    
    .vip-msg .bubble { 
        background: linear-gradient(135deg, #4d3b00 0%, #1a1500 100%) !important; 
        border: 1px solid gold !important;
        box-shadow: 0 0 10px rgba(255, 215, 0, 0.2);
        color: gold !important;
    }

    .time { font-size: 0.6rem; opacity: 0.4; display: block; margin-top: 4px; text-align: right; }
</style>

<script>
    const msgBox = document.getElementById('msgBox');
    const privForm = document.getElementById('privForm');
    const mInput = document.getElementById('mInput');
    const vipToggle = document.getElementById('vipToggle');
    const vipIcon = document.getElementById('vipIcon');
    const vipNotice = document.getElementById('vipNotice');
    const toId = <?php echo $to_id; ?>;
    let lastData = "";

    vipToggle.onchange = function() {
        if(this.checked) {
            vipIcon.style.color = "gold";
            vipNotice.style.display = "block";
        } else {
            vipIcon.style.color = "#555";
            vipNotice.style.display = "none";
        }
    };

    function loadMessages() {
        fetch(`fetch_private.php?to=${toId}`)
            .then(r => r.text())
            .then(data => {
                if (lastData !== data) {
                    lastData = data;
                    msgBox.innerHTML = data;
                    msgBox.scrollTop = msgBox.scrollHeight;
                }
            });
    }

    privForm.onsubmit = function(e) {
        e.preventDefault();
        const msg = mInput.value.trim();
        const isVip = vipToggle.checked ? 1 : 0;
        if(!msg) return;

        fetch('send_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `receiver_id=${toId}&message=${encodeURIComponent(msg)}&is_vip=${isVip}`
        })
        .then(r => r.text())
        .then(res => {
            if(res.includes("ERROR_MONEY")) {
                alert("Недостаточно СМ для золотого сообщения!");
            } else if(res.includes("SUCCESS")) {
                mInput.value = '';
                vipToggle.checked = false;
                vipIcon.style.color = "#555";
                vipNotice.style.display = "none";
                loadMessages();
            } else {
                alert("Ошибка отправки: " + res);
            }
        }).catch(err => console.error("Ошибка:", err));
    };

    setInterval(loadMessages, 3000);
    loadMessages();
</script>

<?php 
if (isset($db)) { @pg_close($db); }
include 'footer.php'; 
?>
