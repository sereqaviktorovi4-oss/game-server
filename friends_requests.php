<?php
// Подключаем db.php (он сам коннектится к БД и запускает сессию)
include 'db.php';
include 'header.php'; 

if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php"); 
    exit; 
}

$my_id = (int)$_SESSION['user_id'];

// Логика принятия заявки с исправленными параметрами PostgreSQL (user_id = отправитель, friend_id = мы)
if (isset($_GET['accept'])) {
    $req_id = (int)$_GET['accept'];
    $update_sql = "UPDATE friends SET status = 'accepted' WHERE user_id = $1 AND friend_id = $2";
    pg_query_params($db, $update_sql, array($req_id, $my_id));
}

// Получаем и ожидающие, и принятые заявки с помощью параметризованного запроса
$requests_sql = "
    SELECT u.id, u.username, u.gender, u.avatar_path, f.status 
    FROM users u 
    JOIN friends f ON u.id = f.user_id 
    WHERE f.friend_id = $1 
    AND (f.status = 'pending' OR f.status = 'accepted')
    ORDER BY f.status DESC
";
$requests = pg_query_params($db, $requests_sql, array($my_id));
?>

<div style="margin-bottom: 25px;">
    <h2 style="color: var(--accent);"><i class="fa-solid fa-user-plus"></i> Заявки в друзья</h2>
    <p style="color: #777; font-size: 0.9rem;">Здесь отображаются те, кто хочет добавить вас в друзья</p>
</div>

<div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
    <?php if ($requests && pg_num_rows($requests) > 0): ?>
        <?php while($r = pg_fetch_assoc($requests)): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; background: #252530; padding: 15px; border-radius: 15px; border: 1px solid <?php echo ($r['status'] == 'accepted') ? 'var(--accent)' : '#333'; ?>;">
                
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 40px; height: 40px; background: #1a1a24; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1px solid #444;">
                        <?php echo ($r['gender'] == 'm' ? '👦' : '👧'); ?>
                    </div>
                    <div>
                        <strong style="color: #fff;"><?php echo htmlspecialchars($r['username']); ?></strong>
                        <div style="font-size: 0.75rem; color: #666;">Хочет стать вашим другом</div>
                    </div>
                </div>

                <div>
                    <?php if ($r['status'] == 'pending'): ?>
                        <a href="?accept=<?php echo (int)$r['id']; ?>" class="btn-action" style="padding: 8px 20px; font-size: 0.8rem; text-decoration: none;">
                            <i class="fa-solid fa-check"></i> ПРИНЯТЬ
                        </a>
                    <?php else: ?>
                        <div style="background: rgba(0, 242, 255, 0.1); color: var(--accent); padding: 8px 15px; border-radius: 8px; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--accent);">
                            <i class="fa-solid fa-user-check"></i> В ДРУЗЬЯХ
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 30px; background: #1a1a24; border-radius: 15px; color: #555;">
            <p>Новых заявок пока нет</p>
            <a href="friends.php" style="color: var(--primary); text-decoration: none;">Вернуться к списку друзей</a>
        </div>
    <?php endif; ?>
</div>

<div style="margin-top: 20px; text-align: center;">
    <a href="friends.php" style="color: #777; text-decoration: none; font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Назад к друзьям</a>
</div>

<?php 
if (isset($db)) { @pg_close($db); }
echo "</div></body></html>"; 
?>
