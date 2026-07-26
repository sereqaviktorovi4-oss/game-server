const { WebSocketServer } = require('ws');
const { Pool } = require('pg');
const bcrypt = require('bcryptjs');
const crypto = require('crypto');

const db = new Pool({
    connectionString: process.env.DATABASE_URL,
    ssl: process.env.DATABASE_URL ? { rejectUnauthorized: false } : false
});

// Полная автоматическая инициализация таблиц базы данных при старте сервера
async function initDatabase() {
    try {
        const client = await db.connect();
        console.log('✅ Успешное подключение к PostgreSQL на Render!');

        await client.query(`
            DO $$ BEGIN
                CREATE TYPE user_platform AS ENUM ('web', 'game', 'offline');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END $$;
        `);

        // 1. Таблица пользователей
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

        // 2. Таблица общего чата (используется api_chat.php)
        await client.query(`
            CREATE TABLE IF NOT EXISTS chat (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50),
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                room_id VARCHAR(50) DEFAULT 'global',
                is_vip INT DEFAULT 0,
                user_id INT DEFAULT 0
            );
        `);

        // 3. Таблица личных сообщений (используется api_privat.php)
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

        // 4. Таблица построек (builds)
        await client.query(`
            CREATE TABLE IF NOT EXISTS builds (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                pos_x REAL DEFAULT 0,
                pos_y REAL DEFAULT 0,
                pos_z REAL DEFAULT 0,
                build_type VARCHAR(50) DEFAULT 'default',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        `);

        // 5. Таблица друзей (friends)
        await client.query(`
            CREATE TABLE IF NOT EXISTS friends (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                friend_id INT NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        `);

        // 6. Таблица запросов в друзья (friend_requests)
        await client.query(`
            CREATE TABLE IF NOT EXISTS friend_requests (
                id SERIAL PRIMARY KEY,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        `);

        console.log('📋 Таблицы успешно проверены и созданы в PostgreSQL!');
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

function req1ResRows(err, res) {
    if (err || !res) return [];
    return res.rows;
}

wss.on('connection', (ws) => {
    let playerId = null;

    ws.on('message', async (message) => {
        try {
            const data = JSON.parse(message);

            // ЛОГИН
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

            // РЕГИСТРАЦИЯ
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
                    db.query("INSERT INTO users (username, password, email) VALUES ($1, $2, $3) RETURNING id", [username, hashedPassword, email || `${username}@mail.com`], (insErr) => {
                        if (insErr) {
                            ws.send(JSON.stringify({ action: "register_response", success: false, message: "Ошибка сохранения" }));
                        } else {
                            ws.send(JSON.stringify({ action: "register_response", success: true, message: "Регистрация успешна!" }));
                        }
                    });
                });
            }

            // ИГРОВОЙ МИР (ПОЗИЦИИ И КОМНАТЫ)
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

            // ДРУЗЬЯ И ЗАЯВКИ
            if (data.action === 'get_friend_requests' || data.action === 'get_friends') {
                const currentUserId = parseInt(data.user_id || ws.user_id);
                if (!currentUserId) return;
                const reqQuery = `
                    SELECT fr.id, fr.sender_id, u.username AS sender_username, u.avatar_path AS sender_avatar
                    FROM friend_requests fr
                    JOIN users u ON u.id = fr.sender_id
                    WHERE fr.recipient_id = $1 AND fr.status = 'pending';
                `;
                const friendsQuery = `
                    SELECT f.friend_id AS id, u.username, u.avatar_path, u.status_text, u.platform
                    FROM friends f
                    JOIN users u ON u.id = f.friend_id
                    WHERE f.user_id = $1 AND f.status = 'active';
                `;
                db.query(reqQuery, [currentUserId], (err1, reqRes) => {
                    db.query(friendsQuery, [currentUserId], (err2, friendRes) => {
                        ws.send(JSON.stringify({
                            action: "friend_data_response",
                            requests: req1ResRows(err1, reqRes),
                            friends: err2 ? [] : friendRes.rows
                        }));
                    });
                });
            }

            if (data.action === 'send_friend_request') {
                const senderId = parseInt(data.user_id || ws.user_id);
                const recipientId = parseInt(data.target_id || data.friend_id);
                if (!senderId || !recipientId || senderId === recipientId) return;

                db.query("INSERT INTO friend_requests (sender_id, recipient_id, status) VALUES ($1, $2, 'pending') ON CONFLICT DO NOTHING RETURNING id", [senderId, recipientId], (err) => {
                    if (!err) {
                        if (playerSockets.has(recipientId)) {
                            playerSockets.get(recipientId).send(JSON.stringify({
                                action: "new_friend_request", sender_id: senderId, username: ws.username || "Игрок"
                            }));
                        }
                        ws.send(JSON.stringify({ action: "send_friend_request_response", success: true }));
                    }
                });
            }

            if (data.action === 'accept_friend') {
                const userId = parseInt(data.user_id || ws.user_id);
                const friendId = parseInt(data.friend_id || data.sender_id);
                if (!userId || !friendId) return;

                db.query("UPDATE friend_requests SET status = 'accepted' WHERE sender_id = $1 AND recipient_id = $2", [friendId, userId], () => {
                    db.query("INSERT INTO friends (user_id, friend_id, status) VALUES ($1, $2, 'active'), ($2, $1, 'active') ON CONFLICT DO NOTHING", [userId, friendId], () => {
                        ws.send(JSON.stringify({ action: "accept_friend_response", success: true, friend_id: friendId }));
                        if (playerSockets.has(friendId)) {
                            playerSockets.get(friendId).send(JSON.stringify({
                                action: "friend_request_accepted", user_id: userId, username: ws.username
                            }));
                        }
                    });
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

console.log(`🚀 Сервер запущен на порту ${PORT}`);
