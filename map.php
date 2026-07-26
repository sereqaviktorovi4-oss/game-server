<?php 
include 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'header.php'; 

if (!isset($_SESSION['user_id'])) { 
    if (isset($db)) { @pg_close($db); }
    header("Location: login.php"); 
    exit; 
}

// Базовые районы города
$districts = [
    "1-1" => "Центральный Парк", "1-2" => "Бизнес Квартал", "1-3" => "Набережная",
    "2-1" => "Старый Город", "2-2" => "Промзона", "2-3" => "Элитный район"
];

$user_id = (int)$_SESSION['user_id'];
// Берем данные текущего пользователя с помощью параметризованного запроса
$user_res = pg_query_params($db, "SELECT plot_coords FROM users WHERE id = $1", array($user_id));
$user = ($user_res) ? pg_fetch_assoc($user_res) : null;
?>

<div style="text-align: center; margin-bottom: 25px;">
    <h2 style="color: var(--accent);"><i class="fa-solid fa-map-location-dot"></i> Карта Love City</h2>
    <p style="color: #aaa;">Выберите район, чтобы зайти в гости или заселиться</p>
</div>

<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; max-width: 700px; margin: 0 auto;">
    <?php foreach ($districts as $coord => $d_name): 
        // Получаем данные владельца и название участка с помощью параметризованного запроса
        $res = pg_query_params($db, "SELECT id, username, plot_name, platform, last_seen FROM users WHERE plot_coords = $1", array($coord));
        $owner = ($res) ? pg_fetch_assoc($res) : null;
        
        $is_mine = ($user && isset($user['plot_coords']) && $user['plot_coords'] == $coord);
        $is_taken = ($owner && !$is_mine);
        
        // Проверка онлайна владельца (с использованием интервала/времени)
        $is_online = false;
        if($owner && !empty($owner['last_seen']) && (time() - strtotime($owner['last_seen']) < 120)) {
            $is_online = true;
        }
    ?>
        <div 
            onclick="handlePlotClick('<?php echo $coord; ?>', '<?php echo $d_name; ?>', <?php echo $is_mine ? 'true' : 'false'; ?>, <?php echo $is_taken ? 'true' : 'false'; ?>, '<?php echo $owner['id'] ?? 0; ?>')"
            style="
                background: <?php echo $is_mine ? 'var(--primary)' : ($is_taken ? '#1a1a24' : '#252530'); ?>;
                padding: 20px; border-radius: 15px; border: 2px solid <?php echo $is_mine ? 'var(--accent)' : ($is_taken ? '#8e2de2' : '#444'); ?>;
                text-align: center; cursor: pointer; transition: 0.3s; position: relative;
            "
            class="plot-card">
            
            <div style="font-size: 0.7rem; color: #666;"><?php echo $coord; ?></div>
            <div style="font-weight: bold; margin: 5px 0; color: #fff;"><?php echo $d_name; ?></div>
            
            <div style="font-size: 0.85rem; margin-top: 10px;">
                <?php if ($is_mine): ?>
                    <div style="color: var(--accent); font-weight: bold;">«<?php echo htmlspecialchars($owner['plot_name'] ?? 'Мой дом'); ?>»</div>
                    <div style="color: #00ff00; font-size: 0.7rem; margin-top:5px;"><i class="fa-solid fa-house-user"></i> Мой дом</div>
                <?php elseif ($is_taken): ?>
                    <div style="color: #ddd;">«<?php echo htmlspecialchars($owner['plot_name'] ?? 'Участок'); ?>»</div>
                    <span style="color: var(--primary); font-size: 0.7rem;">👤 <?php echo htmlspecialchars($owner['username']); ?></span>
                    <?php if($is_online): ?>
                        <span style="color: #00ff00; font-size: 0.6rem;">●</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: #00ff00; font-size: 0.8rem;">Свободно</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
function handlePlotClick(coord, dName, isMine, isTaken, ownerId) {
    if (isMine) {
        if (confirm("Вы хотите войти в свой дом («" + dName + "»)?")) {
            window.location.href = "lovecity://action=home";
        }
    } else if (isTaken) {
        if (confirm("Зайти в гости в районе " + dName + "?")) {
            window.location.href = "lovecity://visit=" + ownerId;
        }
    } else {
        let customName = prompt("Как вы назовете свой участок в районе " + dName + "?", "Моя вилла");
        if (customName != null && customName != "") {
            window.location.href = "claim_plot.php?id=" + coord + "&name=" + encodeURIComponent(customName);
        }
    }
}
</script>

<style>
    .plot-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        border-color: var(--accent) !important;
    }
</style>

<?php 
if (isset($db)) { @pg_close($db); }
echo "</div><p style='text-align:center; color:#555; margin-top:20px;'>Карта обновляется в реальном времени</p></body></html>"; 
?>
