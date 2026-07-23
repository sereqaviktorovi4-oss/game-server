const { WebSocketServer } = require('ws');
const { Pool } = require('pg');

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
                password VARCHAR(100) NOT NULL
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

wss.on('connection', (ws) => {
    let playerId = null;

    ws.on('message', (message) => {
        try {
            const data = JSON.parse(message);

            // ==========================================
            // 0. АВТОРИЗАЦИЯ И РЕГИСТРАЦИЯ (Login / Register)
            // ==========================================
            if (data.action === 'login' || data.type === 'login') {
                const { username, password } = data;

                const query = "SELECT id, username FROM users WHERE username = $1 AND password = $2";
                db.query(query, [username, password], (err, results) => {
                    if (err) {
                        console.error("Ошибка БД при авторизации:", err.message);
                        ws.send(JSON.stringify({ 
                            action: "login_response", 
                            success: false, 
                            message: "Ошибка БД сервера" 
                        }));
                        return;
                    }

                    if (results.rows.length > 0) {
                        const user = results.rows[0];
                        ws.send(JSON.stringify({
                            action: "login_response",
                            success: true,
                            user_id: user.id,
                            username: user.username
                        }));
                        console.log(`🔑 Авторизован игрок: ${user.username} (ID: ${user.id})`);
                    } else {
                        ws.send(JSON.stringify({
                            action: "login_response",
                            success: false,
                            message: "Неверный логин или пароль"
                        }));
                    }
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
                        ws.send(JSON.stringify({
                            action: "current_players",
                            list: roomPlayers
                        }));

                        broadcastToRoom(ws.current_room, {
                            action: "player_joined",
                            player: players[playerId]
                        }, playerId);

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
