const { WebSocketServer } = require('ws');
const { Pool } = require('pg');
const crypto = require('crypto');

// Подключение к PostgreSQL на Render
const db = new Pool({
    connectionString: process.env.DATABASE_URL,
    ssl: process.env.DATABASE_URL ? { rejectUnauthorized: false } : false
});

// Проверяем связь с БД при запуске
db.connect((err, client, release) => {
    if (err) {
        console.error('❌ Ошибка подключения к PostgreSQL:', err.message);
    } else {
        console.log('✅ Успешное подключение к PostgreSQL на Render!');
        
        // Автоматически создаем таблицу пользователей, если ее еще нет
        client.query(`
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(100) NOT NULL,
                email VARCHAR(100)
            );
        `, (tableErr) => {
            release();
            if (tableErr) {
                console.error('Ошибка создания таблицы:', tableErr.message);
            } else {
                console.log('✅ Таблица "users" готова к работе!');
            }
        });
    }
});

const PORT = process.env.PORT || 3000;
const wss = new WebSocketServer({ port: PORT });

const players = {};
const playerSockets = new Map();

console.log(`🚀 Игровой WebSocket-сервер LoveSity запущен на порту ${PORT}!`);

function broadcastToRoom(roomName, packet, excludePlayerId = null) {
    const rawPacket = JSON.stringify(packet);
    playerSockets.forEach((wsClient, pid) => {
        if (wsClient.current_room === roomName && pid !== excludePlayerId && wsClient.readyState === 1) {
            wsClient.send(rawPacket);
        }
    });
}

// Вспомогательные функции хеширования
function md5(text) {
    return crypto.createHash('md5').update(text).digest('hex');
}
function sha1(text) {
    return crypto.createHash('sha1').update(text).digest('hex');
}

wss.on('connection', (ws) => {
    let playerId = null;

    ws.on('message', (message) => {
        try {
            const data = JSON.parse(message);

            // ==========================================
            // 0. АВТОРИЗАЦИЯ (Login)
            // ==========================================
            if (data.action === 'login' || data.type === 'login') {
                const { username, password } = data;
                const passMd5 = md5(password);
                const passSha1 = sha1(password);

                console.log(`\n🔍 [ПОПЫТКА ВХОДА] Логин: "${username}" | Введенный пароль: "${password}"`);

                // Сначала ищем юзера только по имени, чтобы увидеть, какой пароль хранится в БД
                db.query("SELECT id, username, password FROM users WHERE LOWER(username) = LOWER($1)", [username], (err, results) => {
                    if (err) {
                        console.error("Ошибка БД при поиске юзера:", err.message);
                        ws.send(JSON.stringify({ action: "login_response", success: false, message: "Ошибка БД сервера" }));
                        return;
                    }

                    if (results.rows.length === 0) {
                        console.log(`❌ Пользователь с логином "${username}" не найден в базе!`);
                        ws.send(JSON.stringify({ action: "login_response", success: false, message: "Пользователь не найден" }));
                        return;
                    }

                    const user = results.rows[0];
                    const dbPass = user.password;

                    console.log(`📦 Найден юзер в БД: ID=${user.id}, Username="${user.username}"`);
                    console.log(`🔑 Пароль в БД: "${dbPass}"`);
                    console.log(`🧪 Варианты проверки:`);
                    console.log(`   - Текст: "${password}"`);
                    console.log(`   - MD5:   "${passMd5}"`);
                    console.log(`   - SHA1:  "${passSha1}"`);

                    // Сверяем пароль со всеми возможными вариантами (Чистый текст, MD5, SHA1, регистронезависимо)
                    if (dbPass === password || dbPass.toLowerCase() === passMd5 || dbPass.toLowerCase() === passSha1) {
                        console.log(`✅ Пароль совпал! Успешный вход.`);
                        ws.send(JSON.stringify({
                            action: "login_response",
                            success: true,
                            user_id: user.id,
                            username: user.username
                        }));
                    } else {
                        console.log(`❌ Пароли не совпали!`);
                        ws.send(JSON.stringify({
                            action: "login_response",
                            success: false,
                            message: "Неверный логин или пароль"
                        }));
                    }
                });
            }

            // ==========================================
            // 0.1 РЕГИСТРАЦИЯ (Register)
            // ==========================================
            if (data.action === 'register') {
                const { username, password, email } = data;

                if (!username || !password) {
                    ws.send(JSON.stringify({ action: "register_response", success: false, message: "Заполните логин и пароль!" }));
                    return;
                }

                const checkQuery = "SELECT id FROM users WHERE LOWER(username) = LOWER($1)";
                db.query(checkQuery, [username], async (checkErr, checkRes) => {
                    if (checkErr) {
                        console.error("Ошибка проверки при регистрации:", checkErr.message);
                        ws.send(JSON.stringify({ action: "register_response", success: false, message: "Ошибка БД при проверке" }));
                        return;
                    }

                    if (checkRes.rows.length > 0) {
                        ws.send(JSON.stringify({ action: "register_response", success: false, message: "Логин уже занят!" }));
                        return;
                    }

                    const passwordMd5 = md5(password);
                    
                    // Синхронизируем счетчик ID
                    try {
                        await db.query("SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1));");
                    } catch (e) {}

                    const insertQuery = "INSERT INTO users (username, password, email) VALUES ($1, $2, $3) RETURNING id";
                    db.query(insertQuery, [username, passwordMd5, email || ""], (insertErr, insertRes) => {
                        if (insertErr) {
                            console.error("Ошибка сохранения при регистрации:", insertErr.message);
                            // Пробуем без email, если колонки email нет в таблице
                            db.query("INSERT INTO users (username, password) VALUES ($1, $2) RETURNING id", [username, passwordMd5], (err2) => {
                                if (err2) {
                                    ws.send(JSON.stringify({ action: "register_response", success: false, message: "Ошибка сохранения" }));
                                } else {
                                    console.log(`✨ Зарегистрирован новый игрок: ${username}`);
                                    ws.send(JSON.stringify({ action: "register_response", success: true, message: "Регистрация успешна!" }));
                                }
                            });
                            return;
                        }

                        console.log(`✨ Зарегистрирован новый игрок: ${username}`);
                        ws.send(JSON.stringify({ action: "register_response", success: true, message: "Регистрация успешна!" }));
                    });
                });
            }

            // ==========================================
            // 1. ВХОД В МИР (Join)
            // ==========================================
            if (data.action === 'join') {
                playerId = parseInt(data.user_id);
                ws.current_room = data.room || "1-1"; 
                
                db.query("SELECT username FROM users WHERE id = $1", [playerId], (err, results) => {
                    try {
                        let dbUsername = (results && results.rows.length > 0) ? results.rows[0].username : data.username;

                        players[playerId] = {
                            id: playerId,
                            username: dbUsername,
                            x: 0.0,
                            y: 0.5,
                            z: 0.0,
                            room: ws.current_room
                        };
                        
                        ws.user_id = playerId;
                        ws.username = dbUsername;
                        playerSockets.set(playerId, ws);
                        
                        console.log(`[Вход] Игрок ${dbUsername} (ID: ${playerId}) вошел в район [${ws.current_room}]`);

                        const roomPlayers = {};
                        for (let id in players) {
                            if (players[id].room === ws.current_room) {
                                roomPlayers[id] = players[id];
                            }
                        }
                        ws.send(JSON.stringify({ action: "current_players", list: roomPlayers }));

                        broadcastToRoom(ws.current_room, { action: "player_joined", player: players[playerId] }, playerId);

                    } catch (e) {
                        console.error("Ошибка при 'join': ", e);
                    }
                });
            }

            // ==========================================
            // 2. СМЕНА ЛОКАЦИИ (move_to_room)
            // ==========================================
            if (data.action === 'move_to_room' && playerId) {
                const oldRoom = ws.current_room;
                const newRoom = data.room || "1-1";

                if (oldRoom === newRoom) return;

                broadcastToRoom(oldRoom, { action: "player_left", id: playerId }, playerId);

                ws.current_room = newRoom;
                if (players[playerId]) {
                    players[playerId].room = newRoom;
                    players[playerId].x = 0.0;
                    players[playerId].y = 0.5;
                    players[playerId].z = 0.0;
                }

                const roomPlayers = {};
                for (let id in players) {
                    if (players[id].room === newRoom) {
                        roomPlayers[id] = players[id];
                    }
                }
                ws.send(JSON.stringify({ action: "current_players", list: roomPlayers }));

                broadcastToRoom(newRoom, { action: "player_joined", player: players[playerId] }, playerId);
            }

            // ==========================================
            // 3. ПЕРЕДВИЖЕНИЕ (Movement)
            // ==========================================
            if (data.action === 'move' && playerId && players[playerId]) {
                players[playerId].x = parseFloat(data.x);
                players[playerId].y = parseFloat(data.y);
                players[playerId].z = parseFloat(data.z);
                players[playerId].rot_y = parseFloat(data.rot_y);

                broadcastToRoom(ws.current_room, {
                    action: "player_moved",
                    id: playerId,
                    x: data.x,
                    y: data.y,
                    z: data.z,
                    rot_y: data.rot_y
                }, playerId);
            }

            // ==========================================
            // 4. ЧАТ (Chat)
            // ==========================================
            if (data.action === 'chat' && playerId) {
                broadcastToRoom(ws.current_room, {
                    action: "player_chat",
                    username: ws.username,
                    message: data.message
                });
            }

        } catch (err) {
            console.error("Ошибка парсинга WebSocket-пакета: ", err);
        }
    });

    ws.on('close', () => {
        if (playerId) {
            console.log(`[Выход] Игрок ${ws.username} отключился.`);
            broadcastToRoom(ws.current_room, { action: "player_left", id: playerId }, playerId);
            delete players[playerId];
            playerSockets.delete(playerId);
        }
    });
});
