<?php 
include 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'header.php'; 

if (!isset($_SESSION['user_id'])) {
    echo "<div style='text-align:center; padding: 50px;'><h2>Пожалуйста, войдите в систему.</h2></div>";
    echo "</div></body></html>"; exit;
}
?>

<style>
    #chatBox {
        height: 500px; background: #13131a; border: 1px solid #2a2a35;
        padding: 15px; border-radius: 20px; overflow-y: auto;
        display: flex; flex-direction: column; gap: 10px;
        scroll-behavior: smooth;
    }
    .chat-row { display: flex; flex-direction: column; max-width: 85%; margin-bottom: 5px; }
    .other-msg { align-self: flex-start; }
    .my-msg { align-self: flex-end; }
    
    .chat-bubble { padding: 10px 14px; border-radius: 15px; font-size: 0.95rem; position: relative; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
    .other-msg .chat-bubble { background: #1c1c24; color: #eee; border-left: 3px solid var(--primary); border-bottom-left-radius: 2px; }
    .my-msg .chat-bubble { background: #2d1b4d; color: #fff; border-right: 3px solid var(--accent); border-bottom-right-radius: 2px; }
    
    .chat-nick { font-size: 0.75rem; font-weight: bold; margin-bottom: 2px; cursor: pointer; display: inline-block; }
    .chat-nick:hover { color: var(--accent); text-decoration: underline; }
    .msg-time { font-size: 0.65rem; opacity: 0.4; display: block; margin-top: 4px; text-align: right; }

    #emojiPicker {
        display: none; position: absolute; bottom: 70px; left: 10px;
        background: #1c1c24; border: 1px solid #333; border-radius: 15px;
        padding: 10px; grid-template-columns: repeat(5, 1fr); gap: 8px; z-index: 1000;
    }
    #emojiPicker span { cursor: pointer; font-size: 1.5rem; }
    
    #userMenu { display:none; position: fixed; background: #1c1c24; border: 1px solid var(--primary); border-radius: 15px; z-index: 2000; width: 200px; box-shadow: 0 10px 30px #000; overflow: hidden; }
    .menu-item { padding: 12px; cursor: pointer; color: #ccc; border-bottom: 1px solid #2a2a35; }
    .menu-item:hover { background: rgba(142, 45, 226, 0.2); color: var(--accent); }
</style>

<div id="userMenu">
    <div id="menuUserName" style="padding:10px; background:var(--primary); color:#fff; text-align:center; font-weight:bold; font-size:0.8rem;"></div>
    <div class="menu-item" onclick="viewProfile()">👤 Профиль</div>
    <div class="menu-item" onclick="openPrivate()">✉️ Сообщение</div>
    <div class="menu-item" style="color:#ff4444; text-align:center; border:none;" onclick="closeMenu()">Закрыть</div>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 0 10px;">
    <h2 style="color: var(--accent); margin: 0; font-size: 1.3rem;"><i class="fa-solid fa-comments"></i> Чат города</h2>
    <div id="typingStatus" style="font-size: 0.8rem; color: var(--accent); font-style: italic; height: 20px;"></div>
</div>

<div id="chatBox"></div>

<div style="position: relative; margin-top: 15px;">
    <div id="emojiPicker">
        <?php 
        $emojis = ['😊','😂','❤️','😍','🔥','😎','👍','🎮','🏠','🌇','💰','👀','🤝','💬','🎉'];
        foreach($emojis as $e) echo "<span onclick='addEmoji(\"$e\")'>$e</span>";
        ?>
    </div>

    <form id="chatForm" style="display: flex; gap: 8px; background: #1c1c24; padding: 8px; border-radius: 50px; border: 1px solid #333; align-items: center;">
        <button type="button" onclick="toggleEmoji()" style="background:transparent; border:none; color:#777; font-size:1.3rem; cursor:pointer; padding-left:10px;">😊</button>
        <input type="text" id="msgInput" placeholder="Ваше сообщение..." required autocomplete="off" style="flex:1; background:transparent; border:none; color:#fff; outline:none; padding: 5px 10px;">
        <button type="submit" class="btn-action" style="border-radius:50px; width:42px; height:42px; padding:0; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
    </form>
</div>

<script>
    const chatBox = document.getElementById("chatBox");
    const msgInput = document.getElementById("msgInput");
    const userMenu = document.getElementById("userMenu");
    let currentHtml = "";
    let selId = null;
    let isTyping = false;

    function loadMessages() {
        fetch('fetch_messages.php').then(r => r.text()).then(data => {
            if (currentHtml !== data) {
                currentHtml = data;
                chatBox.innerHTML = data;
                chatBox.scrollTop = chatBox.scrollHeight;
            }
        });
        
        fetch('get_typing.php').then(r => r.text()).then(name => {
            const statusDiv = document.getElementById("typingStatus");
            statusDiv.innerText = name ? "✍️ " + name + " пишет..." : "";
        });
    }

    // Логика "Кто-то пишет"
    msgInput.addEventListener('input', () => {
        if(!isTyping) {
            isTyping = true;
            fetch('update_typing.php?status=1');
            setTimeout(() => { 
                isTyping = false; 
                fetch('update_typing.php?status=0');
            }, 3000);
        }
    });

    function toggleEmoji() { const p = document.getElementById("emojiPicker"); p.style.display = (p.style.display === 'grid' ? 'none' : 'grid'); }
    function addEmoji(e) { msgInput.value += e; document.getElementById("emojiPicker").style.display = 'none'; msgInput.focus(); }

    // Клик по чату с отслеживанием именно тега с ником
    chatBox.addEventListener('click', (e) => {
        const nickTarget = e.target.closest('.chat-nick');
        if (nickTarget) {
            selId = nickTarget.getAttribute('data-id');
            const nickName = nickTarget.innerText;
            
            if (!selId || selId === "undefined" || selId == "0") {
                console.error("ID пользователя не найден в атрибуте data-id");
                return;
            }

            document.getElementById('menuUserName').innerText = nickName;
            userMenu.style.display = 'block';
            userMenu.style.left = Math.min(e.pageX, window.innerWidth - 210) + 'px';
            userMenu.style.top = Math.min(e.pageY, window.innerHeight - 150) + 'px';
        }
    });

    document.addEventListener('click', (e) => {
        if (!userMenu.contains(e.target) && !e.target.closest('.chat-nick')) { 
            userMenu.style.display = 'none'; 
        }
    });

    function viewProfile() { 
        if (selId) window.location.href = 'profile.php?id=' + selId; 
    }
    
    function openPrivate() { 
        if (selId) window.location.href = 'inbox.php?to=' + selId; 
    }
    
    function closeMenu() { 
        userMenu.style.display = 'none'; 
    }

    document.getElementById("chatForm").onsubmit = function(e) {
        e.preventDefault();
        const msg = msgInput.value.trim();
        if(!msg) return;
        
        fetch('send_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'message=' + encodeURIComponent(msg)
        })
        .then(response => response.text())
        .then(result => {
            msgInput.value = ''; 
            fetch('update_typing.php?status=0');
            loadMessages(); 
        })
        .catch(error => console.error('Ошибка отправки:', error));
    };

    setInterval(loadMessages, 2500);
    loadMessages();
</script>
</div></body></html>
