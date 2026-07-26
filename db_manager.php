<?php
// Max PostgreSQL Web Manager
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$host     = "dpg-d9grrh6pbkes73c77q80-a";
$port     = "5432";
$dbname   = "lovesity";
$user     = "sereqa";
$password = "UieNKFeX7sMMEpYAE2z99ODEhFYRzeKT";

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
$db = @pg_connect($conn_string);
$error = '';
$success = '';

if (!$db) {
    $error = "Ошибка подключения к базе: " . pg_last_error();
} else {
    $current_table = isset($_GET['table']) ? $_GET['table'] : '';
    $action_get = isset($_GET['action']) ? $_GET['action'] : '';
    $safe_table = !empty($current_table) ? pg_escape_identifier($db, $current_table) : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = isset($_POST['post_action']) ? $_POST['post_action'] : '';

        if ($post_action === 'run_sql') {
            $sql_query = trim(isset($_POST['sql_query']) ? $_POST['sql_query'] : '');
            if (!empty($sql_query)) {
                $res = @pg_query($db, $sql_query);
                if ($res) {
                    $success = "SQL запрос успешно выполнен.";
                    if (pg_num_fields($res) > 0) {
                        $GLOBALS['sql_result'] = $res;
                    }
                } else {
                    $error = "Ошибка SQL: " . pg_last_error($db);
                }
            }
        }

        if ($post_action === 'import_sql' && isset($_FILES['sql_file'])) {
            if ($_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                $sql_content = file_get_contents($_FILES['sql_file']['tmp_name']);
                if (@pg_query($db, $sql_content)) {
                    $success = "SQL файл успешно импортирован!";
                } else {
                    $error = "Ошибка импорта: " . pg_last_error($db);
                }
            } else {
                $error = "Ошибка загрузки файла.";
            }
        }

        if ($post_action === 'add_column' && !empty($current_table)) {
            $col_name = trim(isset($_POST['new_col_name']) ? $_POST['new_col_name'] : '');
            $col_type = isset($_POST['new_col_type']) ? $_POST['new_col_type'] : 'TEXT';
            if (!empty($col_name)) {
                $safe_col = pg_escape_identifier($db, $col_name);
                $q = "ALTER TABLE {$safe_table} ADD COLUMN {$safe_col} {$col_type}";
                if (@pg_query($db, $q)) {
                    $success = "Колонка {$col_name} успешно добавлена.";
                } else {
                    $error = "Ошибка добавления колонки: " . pg_last_error($db);
                }
            }
        }

        if ($post_action === 'drop_column' && !empty($current_table)) {
            $col_name = isset($_POST['col_name']) ? $_POST['col_name'] : '';
            $safe_col = pg_escape_identifier($db, $col_name);
            $q = "ALTER TABLE {$safe_table} DROP COLUMN {$safe_col}";
            if (@pg_query($db, $q)) {
                $success = "Колонка {$col_name} удалена.";
            } else {
                $error = "Ошибка удаления колонки: " . pg_last_error($db);
            }
        }

        if ($post_action === 'rename_table' && !empty($current_table)) {
            $new_name = trim(isset($_POST['new_table_name']) ? $_POST['new_table_name'] : '');
            if (!empty($new_name)) {
                $safe_new = pg_escape_identifier($db, $new_name);
                $q = "ALTER TABLE {$safe_table} RENAME TO {$safe_new}";
                if (@pg_query($db, $q)) {
                    $success = "Таблица переименована в {$new_name}.";
                    $current_table = $new_name;
                    $safe_table = pg_escape_identifier($db, $current_table);
                } else {
                    $error = "Ошибка переименования: " . pg_last_error($db);
                }
            }
        }

        if ($post_action === 'delete_rows' && !empty($current_table)) {
            $ids = isset($_POST['row_ids']) ? $_POST['row_ids'] : array();
            $pk = isset($_POST['pk_name']) ? $_POST['pk_name'] : 'id';
            $safe_pk = pg_escape_identifier($db, $pk);
            if (!empty($ids)) {
                foreach ($ids as $id_val) {
                    @pg_query_params($db, "DELETE FROM {$safe_table} WHERE {$safe_pk} = $1", array($id_val));
                }
                $success = "Выбранные строки удалены.";
            }
        }

        if ($post_action === 'insert_row' && !empty($current_table)) {
            $columns = array(); $values = array(); $params = array(); $i = 1;
            if (isset($_POST['field']) && is_array($_POST['field'])) {
                foreach ($_POST['field'] as $col => $val) {
                    if ($val !== '') {
                        $columns[] = pg_escape_identifier($db, $col);
                        $values[] = '$' . $i++;
                        $params[] = $val;
                    }
                }
            }
            if (!empty($columns)) {
                $q = "INSERT INTO {$safe_table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
                if (@pg_query_params($db, $q, $params)) { $success = "Строка добавлена."; } 
                else { $error = "Ошибка вставки: " . pg_last_error($db); }
            }
        }

        if ($post_action === 'update_row' && !empty($current_table)) {
            $pk = isset($_POST['pk_name']) ? $_POST['pk_name'] : 'id';
            $pk_val = isset($_POST['pk_val']) ? $_POST['pk_val'] : '';
            $set = array(); $params = array(); $i = 1;
            if (isset($_POST['field']) && is_array($_POST['field'])) {
                foreach ($_POST['field'] as $col => $val) {
                    $set[] = pg_escape_identifier($db, $col) . ' = $' . $i++;
                    $params[] = $val;
                }
            }
            $params[] = $pk_val;
            if (!empty($set)) {
                $q = "UPDATE {$safe_table} SET " . implode(', ', $set) . " WHERE " . pg_escape_identifier($db, $pk) . " = $" . $i;
                if (@pg_query_params($db, $q, $params)) { $success = "Строка обновлена."; } 
                else { $error = "Ошибка обновления: " . pg_last_error($db); }
            }
        }
    }

    if (!empty($current_table) && $action_get) {
        if ($action_get === 'truncate') {
            @pg_query($db, "TRUNCATE TABLE {$safe_table} RESTART IDENTITY CASCADE");
            $success = "Таблица очищена.";
        }
        if ($action_get === 'drop') {
            @pg_query($db, "DROP TABLE {$safe_table} CASCADE");
            header("Location: ?"); exit;
        }
        if ($action_get === 'export') {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $current_table . '_export.sql"');
            $rows = pg_query($db, "SELECT * FROM {$safe_table}");
            while ($r = pg_fetch_assoc($rows)) {
                $cols = array_map(function($c) use ($db) { return pg_escape_identifier($db, $c); }, array_keys($r));
                $vals = array_map(function($v) use ($db) { return $v === null ? 'NULL' : pg_escape_literal($db, $v); }, array_values($r));
                echo "INSERT INTO " . $safe_table . " (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Max PostgreSQL Web Manager</title>
    <style>
        body { background: #f4f6f9; color: #333; font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; font-size: 13px; }
        .header { background: #e0e6ed; padding: 10px 20px; border-bottom: 1px solid #ccc; display: flex; align-items: center; gap: 10px; }
        .header h3 { margin: 0; color: #2c3e50; font-size: 16px; }
        .nav-tabs { display: flex; background: #fff; padding: 5px 20px 0; border-bottom: 1px solid #ddd; gap: 5px; }
        .nav-tabs a { padding: 8px 15px; text-decoration: none; color: #0056b3; background: #f8f9fa; border: 1px solid #ddd; border-bottom: none; border-radius: 4px 4px 0 0; }
        .nav-tabs a.active { background: #fff; color: #333; font-weight: bold; border-bottom: 1px solid #fff; margin-bottom: -1px; }
        .container { display: flex; height: calc(100vh - 80px); }
        .sidebar { width: 230px; background: #f8f9fa; border-right: 1px solid #ccc; overflow-y: auto; padding: 10px; }
        .content { flex-grow: 1; padding: 20px; overflow-y: auto; background: #fff; }
        ul.tree { list-style: none; padding-left: 0; margin: 0; }
        ul.tree li { padding: 4px 0; }
        ul.tree a { text-decoration: none; color: #0056b3; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; background: #fff; }
        th, td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; }
        th { background: #f4f6f9; color: #495057; font-weight: bold; border-bottom: 2px solid #dee2e6; }
        tr:nth-child(even) { background: #f8f9fa; }
        .action-link { text-decoration: none; color: #0056b3; display: inline-flex; align-items: center; gap: 3px; margin-right: 6px; }
        .action-link:hover { text-decoration: underline; }
        .text-danger { color: #dc3545 !important; }
        .msg { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        input[type="text"], textarea, select { padding: 5px; border: 1px solid #ccc; border-radius: 3px; width: 100%; box-sizing: border-box; }
        button.btn { background: #007bff; color: #fff; border: none; padding: 6px 14px; border-radius: 3px; cursor: pointer; font-weight: bold; }
        button.btn:hover { background: #0056b3; }
        .card { background: #f8f9fa; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="header">
    <span style="font-size:20px;">🐘</span>
    <h3>База данных: <b><?php echo htmlspecialchars($dbname); ?></b></h3>
</div>

<?php if ($db): ?>
<div class="nav-tabs">
    <?php 
    $tab_val = isset($_GET['tab']) ? $_GET['tab'] : 'tables';
    $tab_class_tables = ($tab_val == 'tables') ? 'active' : '';
    $tab_class_sql = ($tab_val == 'sql') ? 'active' : '';
    $tab_class_import = ($tab_val == 'import') ? 'active' : '';
    ?>
    <a href="?tab=tables" class="<?php echo $tab_class_tables; ?>">Структура БД</a>
    <a href="?tab=sql" class="<?php echo $tab_class_sql; ?>">SQL Запросы</a>
    <a href="?tab=import" class="<?php echo $tab_class_import; ?>">Импорт</a>
</div>

<div class="container">
    <div class="sidebar">
        <ul class="tree">
            <li><b>➖ <?php echo htmlspecialchars($dbname); ?></b>
                <?php
                $tables_res = pg_query($db, "SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name;");
                if ($tables_res) {
                    echo "<ul style='padding-left: 15px; margin-top: 5px;'>";
                    while ($t = pg_fetch_assoc($tables_res)) {
                        $t_name = $t['table_name'];
                        $active = ($current_table === $t_name) ? 'font-weight:bold; color:#000;' : '';
                        echo "<li><a href='?table=" . urlencode($t_name) . "&action=browse' style='{$active}'>📄 " . htmlspecialchars($t_name) . "</a></li>";
                    }
                    echo "</ul>";
                }
                ?>
            </li>
        </ul>
    </div>

    <div class="content">
        <?php if ($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="msg success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <?php
        if ($tab_val === 'sql') {
            echo '<div class="card">';
            echo '<h4>Выполнить SQL запрос</h4>';
            echo '<form method="POST">';
            echo '<input type="hidden" name="post_action" value="run_sql">';
            echo '<textarea name="sql_query" rows="5" placeholder="SELECT * FROM users;"></textarea><br><br>';
            echo '<button type="submit" class="btn">Выполнить</button>';
            echo '</form>';
            echo '</div>';

            if (isset($GLOBALS['sql_result'])) {
                $res = $GLOBALS['sql_result'];
                echo "<table><tr>";
                for ($i=0; $i<pg_num_fields($res); $i++) { echo "<th>" . pg_field_name($res, $i) . "</th>"; }
                echo "</tr>";
                while ($row = pg_fetch_assoc($res)) {
                    echo "<tr>";
                    foreach ($row as $val) { 
                        $val_display = ($val !== null) ? $val : 'NULL';
                        echo "<td>" . htmlspecialchars($val_display) . "</td>"; 
                    }
                    echo "</tr>";
                }
                echo "</table>";
            }
        } elseif ($tab_val === 'import') {
            echo '<div class="card" style="max-width: 500px;">';
            echo '<h4>Импорт SQL файла</h4>';
            echo '<form method="POST" enctype="multipart/form-data">';
            echo '<input type="hidden" name="post_action" value="import_sql">';
            echo '<p><input type="file" name="sql_file" accept=".sql" required></p>';
            echo '<button type="submit" class="btn">Загрузить и выполнить</button>';
            echo '</form>';
            echo '</div>';
        } elseif (empty($current_table)) {
            echo '<table>';
            echo '<tr><th>Таблица</th><th>Действия</th><th style="text-align: right;">Строки</th><th style="text-align: right;">Размер</th></tr>';
            
            $q = "SELECT relname as table_name, n_live_tup as row_count, pg_size_pretty(pg_total_relation_size(relid)) as size FROM pg_stat_user_tables ORDER BY relname;";
            $res = pg_query($db, $q);
            if ($res) {
                while ($t = pg_fetch_assoc($res)) {
                    $t_name = urlencode($t['table_name']);
                    echo '<tr>';
                    echo '<td><a href="?table=' . $t_name . '&action=browse" style="font-weight:bold; color:#0056b3; text-decoration:none;">' . htmlspecialchars($t['table_name']) . '</a></td>';
                    echo '<td>';
                    echo '<a href="?table=' . $t_name . '&action=browse" class="action-link">👁️ Обзор</a>';
                    echo '<a href="?table=' . $t_name . '&action=structure" class="action-link">🔧 Структура</a>';
                    echo '<a href="?table=' . $t_name . '&action=insert" class="action-link">➕ Вставить</a>';
                    echo '<a href="?table=' . $t_name . '&action=export" class="action-link">📦 Экспорт</a>';
                    echo '<a href="?table=' . $t_name . '&action=truncate" class="action-link text-danger" onclick="return confirm(\'Очистить таблицу?\');">🧽 Очистить</a>';
                    echo '<a href="?table=' . $t_name . '&action=drop" class="action-link text-danger" onclick="return confirm(\'Удалить таблицу полностью?\');">⛔ Удалить</a>';
                    echo '</td>';
                    echo '<td style="text-align: right;">' . $t['row_count'] . '</td>';
                    echo '<td style="text-align: right;">' . $t['size'] . '</td>';
                    echo '</tr>';
                }
            }
            echo '</table>';
        } else {
            echo "<h4 style='margin-top:0;'>Таблица: <b>" . htmlspecialchars($current_table) . "</b></h4>";
            echo "<div style='margin-bottom: 15px;'>";
            echo "<a href='?table=" . urlencode($current_table) . "&action=browse' class='action-link'>👁️ Обзор</a> | ";
            echo "<a href='?table=" . urlencode($current_table) . "&action=structure' class='action-link'>🔧 Структура</a> | ";
            echo "<a href='?table=" . urlencode($current_table) . "&action=insert' class='action-link'>➕ Вставить</a> | ";
            echo "<a href='?table=" . urlencode($current_table) . "&action=export' class='action-link'>📦 Экспорт .sql</a> | ";
            echo "<a href='?table=" . urlencode($current_table) . "&action=operations' class='action-link'>⚙️ Операции</a>";
            echo "</div>";

            if ($action_get === 'browse' || empty($action_get)) {
                $rows = @pg_query($db, "SELECT * FROM {$safe_table} LIMIT 100;");
                if ($rows && pg_num_rows($rows) > 0) {
                    echo '<form method="POST">';
                    echo '<input type="hidden" name="post_action" value="delete_rows">';
                    echo '<input type="hidden" name="pk_name" value="id">';
                    echo "<table><tr><th width='20'></th><th>Действия</th>";
                    for ($i=0; $i<pg_num_fields($rows); $i++) { echo "<th>" . pg_field_name($rows, $i) . "</th>"; }
                    echo "</tr>";

                    while ($row = pg_fetch_assoc($rows)) {
                        $row_id = isset($row['id']) ? $row['id'] : '';
                        echo "<tr>";
                        $checkbox_html = ($row_id !== '') ? "<input type='checkbox' name='row_ids[]' value='{$row_id}'>" : "";
                        echo "<td>" . $checkbox_html . "</td>";
                        echo "<td style='white-space:nowrap;'>";
                        if ($row_id !== '') {
                            echo '<a href="?table=' . urlencode($current_table) . '&action=edit&edit_id=' . $row_id . '">✏️</a>';
                        }
                        echo "</td>";
                        foreach ($row as $val) { 
                            $val_display = ($val !== null) ? $val : 'NULL';
                            echo "<td>" . htmlspecialchars($val_display) . "</td>"; 
                        }
                        echo "</tr>";
                    }
                    echo "</table>";
                    echo '<br><button type="submit" class="btn" style="background:#dc3545;" onclick="return confirm(\'Удалить выбранные строки?\');">Удалить выбранные</button>';
                    echo '</form>';
                } else {
                    echo "<p>Таблица пуста.</p>";
                }
            } elseif ($action_get === 'structure') {
                $cols = pg_query($db, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = " . pg_escape_literal($db, $current_table));
                echo "<table><tr><th>Колонка</th><th>Тип</th><th>Действие</th></tr>";
                while ($c = pg_fetch_assoc($cols)) {
                    echo "<tr><td><b>" . htmlspecialchars($c['column_name']) . "</b></td><td>" . htmlspecialchars($c['data_type']) . "</td>";
                    echo "<td>";
                    echo '<form method="POST" onsubmit="return confirm(\'Удалить колонку?\');" style="display:inline;">';
                    echo '<input type="hidden" name="post_action" value="drop_column"><input type="hidden" name="col_name" value="' . htmlspecialchars($c['column_name']) . '">';
                    echo '<button type="submit" style="color:red; background:none; border:none; cursor:pointer;">🗑️ Удалить</button></form>';
                    echo "</td></tr>";
                }
                echo "</table>";

                echo '<div class="card" style="margin-top:20px; max-width:400px;"><h4>Добавить колонку</h4>';
                echo '<form method="POST"><input type="hidden" name="post_action" value="add_column">';
                echo '<p>Имя: <input type="text" name="new_col_name" required></p>';
                echo '<p>Тип: <select name="new_col_type"><option value="TEXT">TEXT</option><option value="INTEGER">INTEGER</option><option value="VARCHAR(255)">VARCHAR(255)</option><option value="BOOLEAN">BOOLEAN</option></select></p>';
                echo '<button type="submit" class="btn">Добавить</button></form></div>';
            } elseif ($action_get === 'operations') {
                echo '<div class="card" style="max-width:400px;"><h4>Переименовать таблицу</h4>';
                echo '<form method="POST"><input type="hidden" name="post_action" value="rename_table">';
                echo '<p>Новое имя: <input type="text" name="new_table_name" value="' . htmlspecialchars($current_table) . '" required></p>';
                echo '<button type="submit" class="btn">Переименовать</button></form></div>';
            } elseif ($action_get === 'insert' || $action_get === 'edit') {
                $is_edit = ($action_get === 'edit' && isset($_GET['edit_id']));
                $edit_id = isset($_GET['edit_id']) ? $_GET['edit_id'] : '';
                $data = array();
                if ($is_edit) {
                    $res = @pg_query_params($db, "SELECT * FROM {$safe_table} WHERE id = $1", array($edit_id));
                    if ($res) $data = pg_fetch_assoc($res);
                }
                $cols = pg_query($db, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = " . pg_escape_literal($db, $current_table));
                
                $form_action_val = $is_edit ? 'update_row' : 'insert_row';
                echo '<form method="POST" class="card">';
                echo '<input type="hidden" name="post_action" value="' . $form_action_val . '">';
                if ($is_edit) {
                    echo '<input type="hidden" name="pk_name" value="id"><input type="hidden" name="pk_val" value="' . htmlspecialchars($edit_id) . '">';
                }
                echo "<table><tr><th>Столбец</th><th>Тип</th><th>Значение</th></tr>";
                while ($c = pg_fetch_assoc($cols)) {
                    $cname = $c['column_name'];
                    $val = $is_edit ? (isset($data[$cname]) ? $data[$cname] : '') : '';
                    echo "<tr><td><b>" . htmlspecialchars($cname) . "</b></td><td>" . htmlspecialchars($c['data_type']) . "</td><td><input type='text' name='field[" . htmlspecialchars($cname) . "]' value='" . htmlspecialchars($val) . "'></td></tr>";
                }
                $btn_text = $is_edit ? 'Обновить' : 'Добавить';
                echo "</table><br><button type='submit' class='btn'>" . $btn_text . "</button></form>";
            }
        }
        ?>
    </div>
</div>
<?php endif; ?>
</body>
</html>
