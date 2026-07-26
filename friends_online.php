<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    pg_close($db);
    header("Location: login.php");
    exit;
}

$my_id = (int)$_SESSION['user_id'];

// Получаем список друзей пользователя, которые находятся в сети (последняя активность менее 5 минут назад)
// В таблице друзей связи могут быть как (user_id = $my_id AND friend_id = x) так и наоборот
$query = "
    SELECT u.id, u.username, u.avatar_path, u.gender, u.last_active, u.district_name, u.plot_coords
    FROM friends f
    JOIN users u ON (
        (f.user_id = $my_id AND f.friend_id = u.id) OR 
        (f.friend_id = $my_id AND f.user_id = u.id)
    )
    WHERE (f.user_id = $my_id OR f.friend_id = $my_id)
      AND f.status = 'accepted'
      AND u.id != $my_id
      AND u.last_active >= NOW() - INTERVAL '5 minutes'
    ORDER BY u.last_active DESC
";

$res = pg_query($db, $query);

// Подключаем шапку сайта
include 'header.php';
?>

<style>
    .online-friends-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .friend-card {
        background: #252530;
        border: 1px solid #333;
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .friend-card:hover {
        border-color: var(--accent);
        transform: translateY(-3px);
        box-shadow: 0 5px 20px rgba(0,0,0,0.4);
    }
    .friend-avatar-wrap {
        position: relative;
        width: 65px;
        height: 65px;
        flex-shrink: 0;
    }
    .friend-avatar {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        background: #1c1c24;
        border: 2px solid var(--primary);
    }
    .friend-online-dot {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 14px;
        height: 14px;
        background: #00ff00;
        border-radius: 50%;
        border: 2px solid #252530;
        box-shadow: 0 0 8px #00ff00;
    }
    .friend-info {
        flex-grow: 1;
        min-width: 0;
    }
    .friend-name {
        font-weight: bold;
        color: #fff;
        text-decoration: none;
        font-size: 1rem;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 4px;
        transition: 0.2s;
    }
    .friend-name:hover {
        color: var(--accent);
    }
    .friend-meta {
        font-size: 0.75rem;
        color: #888;
        margin-bottom: 8px;
    }
    .friend-actions {
        display: flex;
        gap: 8px;
    }
    .btn-mini-action {
        background: #1c1c24;
        color: #bbb;
        border: 1px solid #444;
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: 0.2s;
    }
    .btn-mini-action:hover {
        background: var(--primary);
        color: #fff;
        border-color: var(--primary);
    }
</style>

<div style="background: #252530; padding: 20px 25px; border-radius: 20px; border: 1px solid #333; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
    <div>
        <h2 style="margin: 0 0 5px 0; color: #fff;"><i class="fa-solid fa-user-check" style="color: #00ff00;"></i> Друзья в сети</h2>
        <p style="margin: 0; color: #888; font-size: 0.85rem;">Жители из вашего списка друзей, которые прямо сейчас находятся в городе.</p>
    </div>
    <div>
        <a href="friends.php" class="btn-action" style="text-decoration: none; font-size: 0.85rem; padding: 10px 15px; display: inline-block;"><i class="fa-solid fa-users"></i> Все друзья</a>
    </div>
</div>

<?php if ($res && pg_num_rows($res) > 0): ?>
    <div class="online-friends-grid">
        <?php while ($friend = pg_fetch_assoc($res)): ?>
            <div class="friend-card">
                <div class="friend-avatar-wrap">
                    <?php if (!empty($friend['avatar_path'])): ?>
                        <img src="<?php echo htmlspecialchars($friend['avatar_path']); ?>" class="friend-avatar">
                    <?php else: ?>
                        <div class="friend-avatar" style="display: flex; align-items: center; justify-content: center; font-size: 1.8rem;">
                            <?php echo ($friend['gender'] == 'm' ? '👦' : '👧'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="friend-online-dot" title="В сети"></div>
                </div>

                <div class="friend-info">
                    <a href="profile.php?id=<?php echo (int)$friend['id']; ?>" class="friend-name">
                        <?php echo htmlspecialchars($friend['username']); ?>
                    </a>
                    <div class="friend-meta">
                        <i class="fa-solid fa-location-dot" style="color: var(--accent);"></i> 
                        <?php echo !empty($friend['district_name']) ? htmlspecialchars($friend['district_name']) : 'Love Sector A'; ?>
                    </div>
                    <div class="friend-actions">
                        <a href="messages.php?to=<?php echo (int)$friend['id']; ?>" class="btn-mini-action" title="Написать сообщение">
                            <i class="fa-solid fa-envelope"></i> Чат
                        </a>
                        <?php if (!empty($friend['plot_coords'])): ?>
                            <a href="gamedirect://teleport?location=<?php echo urlencode($friend['plot_coords']); ?>" class="btn-mini-action" title="Телепортироваться к другу">
                                <i class="fa-solid fa-house-chimney"></i> Дом
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <div style="text-align: center; padding: 60px 20px; background: #252530; border-radius: 20px; border: 1px dashed #444;">
        <i class="fa-solid fa-user-slash" style="font-size: 3rem; color: #555; margin-bottom: 15px;"></i>
        <h3 style="color: #bbb; margin: 0 0 10px 0;">Никого из друзей сейчас нет в сети</h3>
        <p style="color: #777; font-size: 0.85rem; margin: 0 0 20px 0;">Возможно, ваши друзья отдыхают или зайдут позже.</p>
        <a href="friends.php" class="btn-action" style="text-decoration: none; display: inline-block; font-size: 0.85rem; padding: 10px 20px;">Перейти к списку друзей</a>
    </div>
<?php endif; ?>

<?php
pg_close($db);
echo "</div></body></html>";
?>
