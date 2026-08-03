<?php
/**
 * 账户配置页面
 * 用于配置当前账户的默认配置
 */

session_start();

define("LOCK_FILE", __DIR__ . "/install.lock");
define("DB_CONFIG_FILE", __DIR__ . "/config/database.inc.php");

if (!file_exists(LOCK_FILE) || !file_exists(DB_CONFIG_FILE)) {
    header("Location: install.php");
    exit;
}

require_once __DIR__ . '/config/database.inc.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

// 检查登录状态
$user_id = null;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} elseif (isset($_COOKIE['qq_login'])) {
    $data = json_decode($_COOKIE['qq_login'], true);
    if ($data && isset($data['username'])) {
        $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "users WHERE username = ? AND enabled = 1");
        $stmt->execute([$data['username']]);
        $user = $stmt->fetch();
        if ($user) {
            $user_id = $user['id'];
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $data['username'];
        }
    }
}

if (!$user_id) {
    header('Location: login/index.html');
    exit;
}

// 获取用户信息
$stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ? AND enabled = 1");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();

if (!$user_data) {
    session_destroy();
    setcookie('qq_login', '', time() - 3600, '/');
    header('Location: login/index.html');
    exit;
}

// 获取配置
$config_group = $_GET['group'] ?? 'noon';
$config_key = $_GET['key'] ?? '';

$message = '';
$config_value = '';

// 获取配置值
if ($config_key) {
    $stmt = $db->prepare("SELECT config_value, value_type FROM " . DB_PREFIX . "default_configs WHERE config_group = ? AND config_key = ?");
    $stmt->execute([$config_group, $config_key]);
    $row = $stmt->fetch();
    if ($row) {
        $config_value = $row['config_value'];
    }
}

// 保存配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $group = $_POST['config_group'] ?? 'noon';
    $key = $_POST['config_key'] ?? '';
    $value = $_POST['config_value'] ?? '';
    $value_type = $_POST['value_type'] ?? 'string';
    $description = $_POST['description'] ?? '';
    
    if ($key) {
        try {
            $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "default_configs 
                                  (config_group, config_key, config_value, value_type, description) 
                                  VALUES (?, ?, ?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE 
                                  config_value = VALUES(config_value), 
                                  value_type = VALUES(value_type), 
                                  description = VALUES(description)");
            $stmt->execute([$group, $key, $value, $value_type, $description]);
            $message = '✅ 配置保存成功';
        } catch (PDOException $e) {
            $message = '❌ 保存失败: ' . $e->getMessage();
        }
    }
}

// 删除配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_config'])) {
    $key = $_POST['config_key'] ?? '';
    $group = $_POST['config_group'] ?? 'noon';
    
    if ($key) {
        try {
            $stmt = $db->prepare("DELETE FROM " . DB_PREFIX . "default_configs WHERE config_group = ? AND config_key = ?");
            $stmt->execute([$group, $key]);
            $message = '✅ 配置已删除';
        } catch (PDOException $e) {
            $message = '❌ 删除失败: ' . $e->getMessage();
        }
    }
}

// 获取所有配置
$stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "default_configs WHERE config_group = ? ORDER BY config_key");
$stmt->execute([$config_group]);
$configs = $stmt->fetchAll();

// 获取所有配置组
$stmt = $db->prepare("SELECT DISTINCT config_group FROM " . DB_PREFIX . "default_configs");
$stmt->execute();
$groups = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 获取用户配置（覆盖）
$stmt = $db->prepare("SELECT config_key, config_value FROM " . DB_PREFIX . "user_configs WHERE user_id = ? AND config_group = ?");
$stmt->execute([$user_id, $config_group]);
$user_configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>账户配置 - Q宠大乐斗助手</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, "PingFang SC", "Helvetica Neue", sans-serif;
            background: #f0f4f8;
            padding: 20px;
            color: #2d3748;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 20px;
            color: #1a3a4a;
        }
        .header .user-info {
            font-size: 14px;
            color: #666;
        }
        .header .user-info strong {
            color: #2e7d32;
        }
        .main {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
        }
        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            height: fit-content;
        }
        .sidebar h3 {
            font-size: 14px;
            color: #888;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .sidebar .group-item {
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            margin-bottom: 2px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sidebar .group-item:hover {
            background: #f0f4ff;
        }
        .sidebar .group-item.active {
            background: #e8f0fe;
            color: #1a73e8;
            font-weight: 500;
        }
        .sidebar .group-item .badge {
            background: #e0e0e0;
            color: #666;
            font-size: 11px;
            padding: 1px 8px;
            border-radius: 10px;
        }
        .sidebar .group-item.active .badge {
            background: #d2e3fc;
            color: #1a73e8;
        }
        .content {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .content .actions {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .content .actions .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            color: white;
        }
        .content .actions .btn-primary {
            background: #1a73e8;
        }
        .content .actions .btn-primary:hover {
            background: #1557b0;
        }
        .content .actions .btn-success {
            background: #34a853;
        }
        .content .actions .btn-success:hover {
            background: #2d9249;
        }
        .content .actions .btn-danger {
            background: #ea4335;
        }
        .content .actions .btn-danger:hover {
            background: #d33426;
        }
        .content .actions .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }
        .content .actions .btn-secondary:hover {
            background: #d0d0d0;
        }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .message.success {
            background: #e8f5e9;
            color: #1e5a2a;
            border: 1px solid #a5d6a7;
        }
        .message.error {
            background: #ffebee;
            color: #b71c1c;
            border: 1px solid #ef9a9a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            text-align: left;
            padding: 10px 12px;
            background: #f5f7fa;
            color: #555;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        table tr:hover td {
            background: #f8faff;
        }
        table .key-cell {
            font-family: monospace;
            font-size: 12px;
            color: #1a73e8;
            word-break: break-all;
            max-width: 300px;
        }
        table .value-cell {
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
            max-width: 300px;
            color: #333;
        }
        table .actions-cell {
            white-space: nowrap;
        }
        table .actions-cell .btn-sm {
            padding: 4px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            color: white;
            margin-right: 4px;
        }
        table .actions-cell .btn-edit {
            background: #1a73e8;
        }
        table .actions-cell .btn-edit:hover {
            background: #1557b0;
        }
        table .actions-cell .btn-delete {
            background: #ea4335;
        }
        table .actions-cell .btn-delete:hover {
            background: #d33426;
        }
        table .actions-cell .btn-override {
            background: #f9a825;
            color: #333;
        }
        table .actions-cell .btn-override:hover {
            background: #f57f17;
            color: white;
        }
        .override-badge {
            background: #fff3e0;
            color: #e65100;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 6px;
        }
        .add-form {
            background: #f5f7fa;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px dashed #d0d0d0;
            display: none;
        }
        .add-form.show {
            display: block;
        }
        .add-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 100px;
            gap: 12px;
            align-items: end;
        }
        .add-form .form-group {
            display: flex;
            flex-direction: column;
        }
        .add-form .form-group label {
            font-size: 12px;
            color: #666;
            margin-bottom: 4px;
            font-weight: 500;
        }
        .add-form .form-group input,
        .add-form .form-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
        }
        .add-form .form-group input:focus,
        .add-form .form-group select:focus {
            outline: none;
            border-color: #1a73e8;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state .icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        @media (max-width: 768px) {
            .main {
                grid-template-columns: 1fr;
            }
            .add-form .form-row {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
        }
        @media (prefers-color-scheme: dark) {
            body { background: #1a2332; color: #d0e0e8; }
            .header { background: #1e2a3a; }
            .header h1 { color: #7aafc9; }
            .sidebar { background: #1e2a3a; }
            .sidebar .group-item:hover { background: #2a3a4a; }
            .sidebar .group-item.active { background: #1a2a4a; color: #64b5f6; }
            .content { background: #1e2a3a; }
            table th { background: #1a2332; color: #9ab0c0; border-bottom-color: #2a3a4a; }
            table td { border-bottom-color: #2a3a4a; }
            table tr:hover td { background: #1a2332; }
            table .key-cell { color: #64b5f6; }
            .add-form { background: #1a2332; border-color: #2a3a4a; }
            .add-form .form-group label { color: #9ab0c0; }
            .add-form .form-group input,
            .add-form .form-group select { background: #1a2332; border-color: #2a4a5a; color: #d0e0e8; }
            .message.success { background: #1a2a1a; border-color: #2a4a2a; }
            .message.error { background: #2a1a1a; border-color: #4a2a2a; }
            .content .actions .btn-secondary { background: #2a3a4a; color: #9ab0c0; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚙️ 账户配置管理</h1>
        <div class="user-info">
            👤 <strong><?= htmlspecialchars($user_data['nickname'] ?: $user_data['username']) ?></strong>
            (<?= htmlspecialchars($user_data['username']) ?>)
        </div>
    </div>

    <div class="main">
        <!-- 侧边栏 -->
        <div class="sidebar">
            <h3>📂 配置分组</h3>
            <?php
            $all_groups = ['noon', 'evening'];
            foreach ($all_groups as $g):
                $count = 0;
                foreach ($configs as $c) {
                    if ($c['config_group'] === $g) $count++;
                }
                $active = ($config_group === $g) ? 'active' : '';
            ?>
                <div class="group-item <?= $active ?>" onclick="window.location.href='?group=<?= $g ?>'">
                    <span><?= $g === 'noon' ? '☀️ 午间' : '🌙 晚间' ?></span>
                    <span class="badge"><?= $count ?></span>
                </div>
            <?php endforeach; ?>
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid #e0e0e0;">
                <a href="index.php" style="color:#667eea;text-decoration:none;font-size:14px;">← 返回主面板</a>
            </div>
        </div>

        <!-- 内容区 -->
        <div class="content">
            <div class="actions">
                <span style="font-size:14px;color:#666;flex:1;">
                    <?= $config_group === 'noon' ? '☀️ 午间配置' : '🌙 晚间配置' ?>
                    (<?= count($configs) ?> 项)
                </span>
                <button class="btn btn-primary" onclick="toggleAddForm()">➕ 新增配置</button>
                <button class="btn btn-secondary" onclick="window.location.reload()">🔄 刷新</button>
            </div>

            <?php if ($message): ?>
                <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <!-- 新增表单 -->
            <div class="add-form" id="addForm">
                <form method="post">
                    <input type="hidden" name="config_group" value="<?= $config_group ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label>配置键</label>
                            <input type="text" name="config_key" placeholder="例如: 元武阁.enabled" required>
                        </div>
                        <div class="form-group">
                            <label>配置值</label>
                            <input type="text" name="config_value" placeholder="例如: 0">
                        </div>
                        <div class="form-group">
                            <label>值类型</label>
                            <select name="value_type">
                                <option value="string">string</option>
                                <option value="int">int</option>
                                <option value="bool">bool</option>
                                <option value="array">array</option>
                                <option value="object">object</option>
                                <option value="null">null</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>描述</label>
                            <input type="text" name="description" placeholder="配置说明" style="min-width:80px;">
                        </div>
                    </div>
                    <div style="margin-top:12px;display:flex;gap:10px;">
                        <button type="submit" name="save_config" class="btn btn-success">💾 保存</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">取消</button>
                    </div>
                </form>
            </div>

            <!-- 配置列表 -->
            <?php if (empty($configs)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <p>暂无配置，点击 "新增配置" 添加</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:30%;">配置键</th>
                            <th style="width:30%;">配置值</th>
                            <th style="width:15%;">类型</th>
                            <th style="width:15%;">描述</th>
                            <th style="width:10%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($configs as $config): 
                            $is_override = isset($user_configs[$config['config_key']]);
                        ?>
                            <tr>
                                <td class="key-cell">
                                    <?= htmlspecialchars($config['config_key']) ?>
                                    <?php if ($is_override): ?>
                                        <span class="override-badge">已覆盖</span>
                                    <?php endif; ?>
                                </td>
                                <td class="value-cell">
                                    <?= htmlspecialchars($config['config_value'] ?: '(空)') ?>
                                </td>
                                <td><?= htmlspecialchars($config['value_type'] ?? 'string') ?></td>
                                <td><?= htmlspecialchars($config['description'] ?? '') ?></td>
                                <td class="actions-cell">
                                    <button class="btn-sm btn-edit" onclick="editConfig('<?= $config_group ?>', '<?= addslashes($config['config_key']) ?>')">编辑</button>
                                    <button class="btn-sm btn-delete" onclick="deleteConfig('<?= $config_group ?>', '<?= addslashes($config['config_key']) ?>')">删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleAddForm() {
    document.getElementById('addForm').classList.toggle('show');
}

function editConfig(group, key) {
    // 跳转到编辑页面
    window.location.href = '?group=' + group + '&key=' + encodeURIComponent(key);
}

function deleteConfig(group, key) {
    if (!confirm('确定要删除配置 "' + key + '" 吗？')) return;
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="config_group" value="${group}">
        <input type="hidden" name="config_key" value="${key}">
        <input type="hidden" name="delete_config" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
}

// 键盘快捷键 Ctrl+Enter 保存
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'Enter') {
        var form = document.querySelector('#addForm form');
        if (form && document.getElementById('addForm').classList.contains('show')) {
            form.submit();
        }
    }
});
</script>
</body>
</html>