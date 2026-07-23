const { WebSocketServer } = require('ws');
const mysql = require('mysql2');

// Подключение к базе данных InfinityFree (lovesity)
const db = mysql.createPool({
    host: 'sql206.infinityfree.com',
    user: 'if0_38379031',
    password: 'sx2cuTTkpnJ',
    database: 'if0_38379031_lovesity',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

// Проверка подключения к БД при запуске
db.getConnection((err, connection) => {
    if (err) {
        console.error('❌ Ошибка подключения к БД MySQL:', err.message);
    } else {
        console.log('✅ Успешное подключение к БД MySQL (InfinityFree)!');
        connection.release();
    }
});

// Настройка порта (Render автоматически передает динамический PORT)
const PORT = process.env.PORT || 3000;
const wss = new WebSocketServer({ port: PORT });

const players = {};
const playerSockets = new Map();

console.log(`🚀 Игровой WebSocket-сервер LoveSity запущен на порту ${PORT}!`);

// Функция для вещания пакета по конкретной комнате
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
            // 0. ПРЯМАЯ АВТОРИЗАЦИЯ (Login)
            // ==========================================
            if (data.action === 'login' || data.type === 'login') {
                const { username, password } = data;

                const query = "SELECT id, username FROM users WHERE username = ? AND password = ?";
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

                    if (results.length > 0) {
                        const user = results[0];
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
                
                // Читаем комнату из пакета (клиент присылает свою текущую локацию)
                ws.current_room = data.room || "1-1"; 
                
                db.query("SELECT username FROM users WHERE id = ?", [playerId], (err, results) => {
                    try {
                        if (err) throw err;
                        
                        let dbUser = (results && results.length > 0) ? results[0] : null;
                        let dbUsername = dbUser ? dbUser.username : data.username;

                        // Спавним на новой сцене
                        players[playerId] = {
                            id: playerId,
                            username: dbUsername,
                            x: 0.0,
                            y: 0.5, // Ноги персонажа ровно на земле
                            z: 0.0,
                            room: ws.current_room
                        };
                        
                        ws.user_id = playerId;
                        ws.username = dbUsername;
                        playerSockets.set(playerId, ws);
                        
                        console.log(`[Вход] Игрок ${dbUsername} (ID: ${playerId}) вошел в район [${ws.current_room}] на спавн (0, 0.5, 0)`);

                        // Отсылаем зашедшему игроку список ВСЕХ, кто в ТОЙ ЖЕ комнате
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

                        // Оповещаем остальных игроков ЭТОЙ комнаты о новом игроке
                        broadcastToRoom(ws.current_room, {
                            action: "player_joined",
                            player: players[playerId]
                        }, playerId);

                    } catch (e) {
                        console.error("Ошибка во время авторизации на WebSocket-сервере: ", e);
                    }
                });
            }

            // ==========================================
            // 2. ДИНАМИЧЕСКИЙ ПЕРЕХОД В ДРУГУЮ ЛОКАЦИЮ (move_to_room)
            // ==========================================
            if (data.action === 'move_to_room' && playerId) {
                const oldRoom = ws.current_room;
                const newRoom = data.room || "1-1";

                if (oldRoom === newRoom) return;

                console.log(`[Телепорт] Игрок ${ws.username} (ID: ${playerId}) меняет локацию: [${oldRoom}] -> [${newRoom}]`);

                // А. Сообщаем игрокам из СТАРОЙ комнаты, что мы ушли
                broadcastToRoom(oldRoom, {
                    action: "player_left",
                    id: playerId
                }, playerId);

                // Б. Переключаем комнату у игрока
                ws.current_room = newRoom;
                if (players[playerId]) {
                    players[playerId].room = newRoom;
                    // Сбрасываем позицию игрока на точку спавна в новой сцене
                    players[playerId].x = 0.0;
                    players[playerId].y = 0.5;
                    players[playerId].z = 0.0;
                }

                // В. Отправляем игроку список тех, кто сейчас находится в НОВОЙ локации
                const roomPlayers = {};
                for (let id in players) {
                    if (players[id].room === newRoom) {
                        roomPlayers[id] = players[id];
                    }
                }
                ws.send(JSON.stringify({
                    action: "current_players",
                    list: roomPlayers
                }));

                // Г. Оповещаем игроков в НОВОЙ комнате, что мы к ним переместились
                broadcastToRoom(newRoom, {
                    action: "player_joined",
                    player: players[playerId]
                }, playerId);
            }

            // ==========================================
            // 3. ПЕРЕДВИЖЕНИЕ (Movement)
            // ==========================================
            if (data.action === 'move' && playerId && players[playerId]) {
                players[playerId].x = parseFloat(data.x);
                players[playerId].y = parseFloat(data.y);
                players[playerId].z = parseFloat(data.z);
                players[playerId].rot_y = parseFloat(data.rot_y);

                // Рассылаем координаты перемещения ТОЛЬКО игрокам этой комнаты
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
            // 4. ОБЩИЙ ЧАТ (Chat)
            // ==========================================
            if (data.action === 'chat' && playerId) {
                // Вещаем чат только тем, кто находится в одной комнате с отправителем
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
            
            // Оповещаем только игроков его последней комнаты
            broadcastToRoom(ws.current_room, {
                action: "player_left",
                id: playerId
            }, playerId);

            delete players[playerId];
            playerSockets.delete(playerId);
        }
    });
});
