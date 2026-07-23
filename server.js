const { WebSocketServer } = require('ws');
const { Pool } = require('pg');
const bcrypt = require('bcryptjs');
const crypto = require('crypto');

const db = new Pool({
    connectionString: process.env.DATABASE_URL,
    ssl: process.env.DATABASE_URL ? { rejectUnauthorized: false } : false
});

// Автоматическая инициализация базы данных при старте сервера
async function initDatabase() {
    try {
        const client = await db.connect();
        console.log('✅ Успешное подключение к PostgreSQL на Render!');

        // Создаем тип ENUM, если его нет
        await client.query(`
            DO $$ BEGIN
                CREATE TYPE user_platform AS ENUM ('web', 'game', 'offline');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END $$;
        `);

        // Создаем таблицу users, если она отсутствует
        await client.query(`
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(100) NOT NULL,
                gender CHAR(1) DEFAULT NULL,
                birth_year INT DEFAULT NULL,
                selected_character VARCHAR(50) DEFAULT NULL,
                plot_coords VARCHAR(50) DEFAULT '1-1',
                name_changes INT DEFAULT 0,
                status_text VARCHAR(255) DEFAULT 'Житель Love City',
                avatar_path VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                plot_name VARCHAR(100) DEFAULT 'Мой участок',
                last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                money INT DEFAULT 100,
                citymoney INT DEFAULT 100,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                platform user_platform DEFAULT 'offline',
                is_typing_at INT DEFAULT 0,
                pos_x REAL DEFAULT 0,
                pos_y REAL DEFAULT 0,
                pos_z REAL DEFAULT 0
            );
        `);

        // Создаем таблицу личных сообщений, если ее нет
        await client.query(`
            CREATE TABLE IF NOT EXISTS private_messages (
                id SERIAL PRIMARY KEY,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        `);

        // Проверка структуры таблиц в консоли Render
        const tableCheck = await client.query(`
            SELECT table_name, column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name IN ('users', 'private_messages');
        `);
        console.log('📋 Структура таблиц в БД:', JSON.stringify(tableCheck.rows, null, 2));

        // Проверяем, есть ли уже пользователи в таблице. Если пусто — заливаем базовых игроков
        const resCheck = await client.query("SELECT COUNT(*) FROM users;");
        if (parseInt(resCheck.rows[0].count) === 0) {
            console.log('📦 Таблица users пуста. Заливаем стартовых игроков...');
            
            await client.query(`
                INSERT INTO users (id, username, password, email, gender, birth_year, selected_character, plot_coords, name_changes, status_text, avatar_path, created_at, plot_name, last_active, money, citymoney, last_seen, platform, is_typing_at, pos_x, pos_y, pos_z) VALUES
                (1, 'sereqa', '$2y$10$5cqEQA0OH9ChAaX0RqG2f.O7jM9x5uTOTfR8lUDwdNIZI7VLSgKBW', 'sereqaviktorovi4@gmail.com', 'm', 1981, NULL, '1-3', 0, 'Король этого города', 'uploads/avatars/avatar_u1_1776072071_26a0fc9a.jpg', '2026-04-13 09:20:53', 'мой дом', '2026-07-22 10:49:24', 99650, 240, '2026-07-22 10:49:24', 'game', 0, 0, 0, 0),
                (11, 'anna', '$2y$10$xKuOZcjxHsf33svc6vlbpuXOqUORY1Xodtx2bSkTelSthgls61Fui', 'ЭМАИЛ', 'm', 2000, NULL, '2-2', 0, 'Житель Love City', '', '2026-04-30 05:31:14', 'Мой участок', '2026-07-22 14:34:08', 950, 400, '2026-07-22 14:34:08', 'game', 0, 0, 0, 0),
                (21, 'kfffvlnfrt', '$2y$10$7E4XFjTfgnnRDp0a3HM0T.3r63h3v/HijxgoGZ76Eu18BLBo4/aBG', 'nuphwvqe@immenseignite.info', 'm', 2000, NULL, '1-1', 0, 'Житель Love City', '', '2026-06-24 18:53:07', 'Мой участок', '2026-06-24 18:53:07', 100, 100, '2026-06-24 18:53:07', 'offline', 0, 0, 0, 0),
                (22, 'wtvfzlyfjo', '$2y$10$jGUlwfJ4IewRuoqTQUMvquxaTKMzoMPFkXIrsCOkv/v.7EM3BGCka', 'xlmphrqv@immenseignite.info', 'm', 2000, NULL, '1-1', 0, 'Житель Love City', '', '2026-06-24 18:53:12', 'Мой участок', '2026-06-24 18:53:12', 100, 100, '2026-06-24 18:53:12', 'offline', 0, 0, 0, 0),
                (23, 'vhoooqxxuh', '$2y$10$BSKhVxDv9VOBetzrXEDrM.8/SEN6WLC08zFzdouf.7zpdhNbBVe.6', 'hqtvoxxk@immenseignite.info', 'm', 2000, NULL, '1-1', 0, 'Житель Love City', '', '2026-06-24 18:53:42', 'Мой участок', '2026-06-24 18:53:42', 100, 100, '2026-06-24 18:53:42', 'offline', 0, 0, 0, 0);
            `);

            // Синхронизируем счетчик ID после вставки
            await client.query("SELECT setval('users_id_seq', 24, false);");
            console.log('✨ Стартовые пользователи успешно загружены!');
        }

        client.release();
    } catch (e) {
        console.error('❌ Ошибка инициализации БД:', e.message);
    }
}

initDatabase();

const PORT = process.env.PORT || 3000;
const wss = new WebSocketServer({ port: PORT });

const players = {};
const playerSockets = new Map();

function broadcastToRoom(roomName, packet, excludePlayerId = null) {
    const rawPacket = JSON.stringify(packet);
    playerSockets.forEach((wsClient, pid) => {
        if (wsClient.current_room === roomName && pid !== excludePlayerId && wsClient.readyState === 1) {
            wsClient.send(rawPacket);
        }
    });
}

function md5(text) {
    return crypto.createHash('md5').update(text).digest('hex');
}

wss.on('connection', (ws) => {
    let playerId = null;

    ws.on('message', async (message) => {
        try {
            const data = JSON.parse(message);

            // ==========================================
            // ЛОГИН (Login)
            // ==========================================
            if (data.action === 'login' || data.type === 'login') {
                const { username, password } = data;

                db.query("SELECT id, username, password FROM users WHERE LOWER(username) = LOWER($1)", [username], async (err, results) => {
                    if (err || results.rows.length === 0) {
                        ws.send(JSON.stringify({ action: "login_response", success: false, message: "Неверный логин или пароль" }));
                        return;
                    }

                    const user = results.rows[0];
                    let isValid = false;

                    if (user.password.startsWith('$2a$') || user.password.startsWith('$2y$') || user.password.startsWith('$2b$')) {
                        isValid = await bcrypt.compare(password, user.password);
                    } else {
                        isValid = (user.password === password || user.password === md5(password));
                    }

                    if (isValid) {
                        ws.send(JSON.stringify({ action: "login_response", success: true, user_id: user.id, username: user.username }));
                        console.log(`🔑 Успешный вход: ${user.username}`);
                    } else {
                        ws.send(JSON.stringify({ action: "login_response", success: false, message: "Неверный логин или пароль" }));
                    }
                });
            }

            // ==========================================
            // РЕГИСТРАЦИЯ (Register)
            // ==========================================
            if (data.action === 'register') {
                const { username, password, email } = data;

                if (!username || !password) {
                    ws.send(JSON.stringify({ action: "register_response", success: false, message: "Заполните все поля!" }));
                    return;
                }

                db.query("SELECT id FROM users WHERE LOWER(username) = LOWER($1)", [username], async (checkErr, checkRes) => {
                    if (checkRes && checkRes.rows.length > 0) {
                        ws.send(JSON.stringify({ action: "register_response", success: false, message: "Логин уже занят!" }));
                        return;
                    }

                    const hashedPassword = await bcrypt.hash(password, 10);
                    const insertQuery = "INSERT INTO users (username, password, email) VALUES ($1, $2, $3) RETURNING id";
                    
                    db.query(insertQuery, [username, hashedPassword, email || `${username}@mail.com`], (insErr, insRes) => {
                        if (insErr) {
                            console.error("Ошибка регистрации:", insErr.message);
                            ws.send(JSON.stringify({ action: "register_response", success: false, message: "Ошибка сохранения" }));
                        } else {
                            console.log(`✨ Зарегистрирован новый игрок: ${username}`);
                            ws.send(JSON.stringify({ action: "register_response", success: true, message: "Регистрация успешна!" }));
                        }
                    });
                });
            }

            // ==========================================
            // ИГРОВОЙ МИР (Join / Move / Chat)
            // ==========================================
            if (data.action === 'join') {
                playerId = parseInt(data.user_id);
                ws.current_room = data.room || "1-1"; 
                
                db.query("SELECT username FROM users WHERE id = $1", [playerId], (err, results) => {
                    let dbUsername = (results && results.rows.length > 0) ? results.rows[0].username : data.username;

                    players[playerId] = { id: playerId, username: dbUsername, x: 0.0, y: 0.5, z: 0.0, room: ws.current_room };
                    ws.user_id = playerId;
                    ws.username = dbUsername;
                    playerSockets.set(playerId, ws);

                    const roomPlayers = {};
                    for (let id in players) {
                        if (players[id].room === ws.current_room) roomPlayers[id] = players[id];
                    }
                    ws.send(JSON.stringify({ action: "current_players", list: roomPlayers }));
                    broadcastToRoom(ws.current_room, { action: "player_joined", player: players[playerId] }, playerId);
                });
            }

            if (data.action === 'move_to_room' && playerId) {
                const oldRoom = ws.current_room;
                const newRoom = data.room || "1-1";
                if (oldRoom === newRoom) return;

                broadcastToRoom(oldRoom, { action: "player_left", id: playerId }, playerId);
                ws.current_room = newRoom;
                if (players[playerId]) {
                    players[playerId].room = newRoom;
                    players[playerId].x = 0.0; players[playerId].y = 0.5; players[playerId].z = 0.0;
                }

                const roomPlayers = {};
                for (let id in players) {
                    if (players[id].room === newRoom) roomPlayers[id] = players[id];
                }
                ws.send(JSON.stringify({ action: "current_players", list: roomPlayers }));
                broadcastToRoom(newRoom, { action: "player_joined", player: players[playerId] }, playerId);
            }

            if (data.action === 'move' && playerId && players[playerId]) {
                players[playerId].x = parseFloat(data.x);
                players[playerId].y = parseFloat(data.y);
                players[playerId].z = parseFloat(data.z);
                players[playerId].rot_y = parseFloat(data.rot_y);

                broadcastToRoom(ws.current_room, {
                    action: "player_moved", id: playerId, x: data.x, y: data.y, z: data.z, rot_y: data.rot_y
                }, playerId);
            }

            // ОБЩИЙ ЧАТ ЛОКАЦИИ
            if (data.action === 'chat') {
                if (!playerId && ws.user_id) {
                    playerId = ws.user_id;
                }
                if (!playerId && data.user_id) {
                    playerId = parseInt(data.user_id);
                    ws.user_id = playerId;
                }

                if (!playerId) {
                    console.log("⚠️ Попытка отправить сообщение в чат без playerId!", data);
                    return;
                }
                
                const senderName = ws.username || data.username || "Игрок";
                const currentRoom = ws.current_room || data.room || "1-1";

                console.log(`💬 Чат [${currentRoom}] ${senderName}: ${data.message || data.text}`);

                broadcastToRoom(currentRoom, { 
                    action: "player_chat", 
                    username: senderName, 
                    sender: senderName,
                    message: data.message || data.text 
                });
            }

            // ==========================================
            // ЛИЧНЫЕ СООБЩЕНИЯ И ДИАЛОГИ (ЧАТЫ)
            // ==========================================

            if (data.action === 'get_chats') {
                const currentUserId = parseInt(data.user_id || ws.user_id);
                if (!currentUserId) return;

                const chatsQuery = `
                    SELECT DISTINCT ON (partner_id)
                        partner_id,
                        u.username AS partner_username,
                        u.avatar_path AS partner_avatar,
                        pm.message AS last_message,
                        pm.created_at AS last_time,
                        pm.sender_id
                    FROM (
                        SELECT id, sender_id AS partner_id, recipient_id, message, created_at, sender_id FROM private_messages WHERE recipient_id = $1
                        UNION ALL
                        SELECT id, recipient_id AS partner_id, sender_id, message, created_at, sender_id FROM private_messages WHERE sender_id = $1
                    ) pm
                    JOIN users u ON u.id = pm.partner_id
                    ORDER BY partner_id, pm.created_at DESC;
                `;

                db.query(chatsQuery, [currentUserId], (err, results) => {
                    if (err) {
                        console.error("Ошибка при получении чатов:", err);
                        ws.send(JSON.stringify({ action: "get_chats", status: "error", chats: [] }));
                    } else {
                        ws.send(JSON.stringify({
                            action: "get_chats",
                            status: "success",
                            chats: results.rows
                        }));
                    }
                });
            }

            if (data.action === 'get_history') {
                const currentUserId = parseInt(data.user_id || ws.user_id);
                const partnerId = parseInt(data.partner_id || data.with_user_id || data.recipient_id);

                if (!currentUserId || !partnerId) return;

                const historyQuery = `
                    SELECT id, sender_id, recipient_id, message, created_at
                    FROM private_messages
                    WHERE (sender_id = $1 AND recipient_id = $2)
                       OR (sender_id = $2 AND recipient_id = $1)
                    ORDER BY created_at ASC
                    LIMIT 100;
                `;

                db.query(historyQuery, [currentUserId, partnerId], (err, results) => {
                    if (err) {
                        console.error("Ошибка при получении истории:", err);
                        ws.send(JSON.stringify({ action: "get_history", status: "error", messages: [] }));
                    } else {
                        ws.send(JSON.stringify({
                            action: "get_history",
                            status: "success",
                            partner_id: partnerId,
                            messages: results.rows
                        }));
                    }
                });
            }

            if (data.action === 'send_message' || data.action === 'private_chat') {
                const senderId = parseInt(data.user_id || ws.user_id);
                const recipientId = parseInt(data.recipient_id || data.target_id);
                const msgText = data.message || data.text;

                if (!senderId || !recipientId || !msgText) return;

                const insertMsg = `
                    INSERT INTO private_messages (sender_id, recipient_id, message)
                    VALUES ($1, $2, $3) RETURNING id, created_at;
                `;

                db.query(insertMsg, [senderId, recipientId, msgText], (err, res) => {
                    if (err) {
                        console.error("Ошибка сохранения сообщения:", err);
                        ws.send(JSON.stringify({ action: "send_message", status: "error" }));
                        return;
                    }

                    const savedMsg = res.rows[0];
                    const packet = {
                        action: "private_chat",
                        id: savedMsg.id,
                        sender_id: senderId,
                        recipient_id: recipientId,
                        sender_name: ws.username || "Игрок",
                        message: msgText,
                        created_at: savedMsg.created_at
                    };

                    ws.send(JSON.stringify({ action: "send_message", status: "success", message_data: packet }));

                    if (playerSockets.has(recipientId)) {
                        const recipientSocket = playerSockets.get(recipientId);
                        if (recipientSocket.readyState === 1) {
                            recipientSocket.send(JSON.stringify(packet));
                        }
                    }
                });
            }

        } catch (err) {
            console.error("Ошибка пакета:", err);
        }
    });

    ws.on('close', () => {
        if (playerId) {
            broadcastToRoom(ws.current_room, { action: "player_left", id: playerId }, playerId);
            delete players[playerId];
            playerSockets.delete(playerId);
        }
    });
});
