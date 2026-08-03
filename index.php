<?php
session_start();

define("LOCK_FILE", __DIR__ . "/install.lock");
define("DB_CONFIG_FILE", __DIR__ . "/config/database.inc.php");

if (!file_exists(LOCK_FILE) || !file_exists(DB_CONFIG_FILE)) {
    header("Location: install.php");
    exit;
}
session_start();

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

// 获取该用户的所有QQ账号
$stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "accounts WHERE user_id = ? AND enabled = 1 ORDER BY sort_order");
$stmt->execute([$user_id]);
$accounts = $stmt->fetchAll();

// ============================================
// 从 noon.py 和 evening.py 动态获取任务列表
// ============================================
function extract_tasks_from_py($file_path) {
    $tasks = array();
    if (!file_exists($file_path)) {
        return $tasks;
    }
    
    $content = file_get_contents($file_path);
    
    preg_match_all('/@register\s*\(\s*\)\s*async\s+def\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $content, $matches);
    
    if (!empty($matches[1])) {
        $tasks = array_unique($matches[1]);
        $filtered_tasks = array();
        foreach ($tasks as $t) {
            if (strpos($t, '_') !== 0) {
                $filtered_tasks[] = $t;
            }
        }
        $tasks = $filtered_tasks;
        $tasks = array_values($tasks);
    }
    
    return $tasks;
}

$noon_file = __DIR__ . "/src/tasks/noon.py";
$evening_file = __DIR__ . "/src/tasks/evening.py";

$noon_tasks = extract_tasks_from_py($noon_file);
$evening_tasks = extract_tasks_from_py($evening_file);

$n = $noon_tasks;
$e = $evening_tasks;
$map = [];
foreach ($n as $task) {
    $map[$task][] = "noon";
}
foreach ($e as $task) {
    if (isset($map[$task])) {
        $map[$task][] = "evening";
    } else {
        $map[$task][] = "evening";
    }
}
$all = array_unique(array_merge($n, $e));
sort($all);

// ============================================
// 快捷任务 - 从数据库读取，如果没有则从文件初始化
// ============================================
$stmt = $db->prepare("SELECT task_name FROM " . DB_PREFIX . "quick_tasks WHERE user_id = ? ORDER BY sort_order");
$stmt->execute([$user_id]);
$quick_rows = $stmt->fetchAll();
$quick = array_column($quick_rows, 'task_name');

// 如果数据库中没有快捷任务，从文件初始化
if (empty($quick)) {
    $default_quick = array_slice($all, 0, 12);
    foreach ($default_quick as $idx => $task) {
        $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "quick_tasks (user_id, task_name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $task, $idx]);
    }
    $quick = $default_quick;
}

// 保存快捷任务（用户自定义）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quick_tasks'])) {
    $s = $_POST['quick_tasks'] ?? [];
    if (!empty($s)) {
        $stmt = $db->prepare("DELETE FROM " . DB_PREFIX . "quick_tasks WHERE user_id = ?");
        $stmt->execute([$user_id]);
        foreach ($s as $idx => $task) {
            if (!empty($task)) {
                $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "quick_tasks (user_id, task_name, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $task, $idx]);
            }
        }
        header('Location: ?saved=1');
        exit;
    }
}

// ============================================
// 执行任务
// ============================================
$b = __DIR__ . '/';
$uv = "/usr/local/bin/uv";
$a = $_GET['act'] ?? '';

if ($a === 'stop') {
    header('Content-Type: application/json;charset=utf-8');
    exit(json_encode(['ok' => 1]));
}

if ($a === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    ob_implicit_flush(1);
    
    header('Content-Type: text/plain;charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Connection: keep-alive');
    header('Content-Encoding: none');
    
    $u = trim($_POST['uin'] ?? '');
    $t = trim($_POST['task'] ?? '');
    
    error_log("执行任务: user_id=" . $user_id . ", uin=" . $u . ", task=" . $t);
    
    // 验证账号是否存在并获取Cookie
    $stmt = $db->prepare("SELECT id, newuin, open_id, access_token FROM " . DB_PREFIX . "accounts WHERE user_id = ? AND newuin = ? AND enabled = 1");
    $stmt->execute([$user_id, $u]);
    $account = $stmt->fetch();
    
    if (!$account) {
        echo "❌ 错误: 所选账号不存在\n";
        echo "   user_id: " . $user_id . "\n";
        echo "   newuin: " . $u . "\n";
        
        $stmt2 = $db->prepare("SELECT newuin, nickname FROM " . DB_PREFIX . "accounts WHERE user_id = ? AND enabled = 1");
        $stmt2->execute([$user_id]);
        $available = $stmt2->fetchAll();
        if ($available) {
            echo "   可用账号: \n";
            foreach ($available as $acc) {
                echo "     - " . $acc['newuin'] . ($acc['nickname'] ? " (" . $acc['nickname'] . ")" : "") . "\n";
            }
        } else {
            echo "   当前账户下没有子账号，请先添加\n";
        }
        exit;
    }
    
    $cookieParams = [
        'openId' => $account['open_id'],
        'accessToken' => $account['access_token'],
        'newuin' => $account['newuin']
    ];
    $cookieStr = json_encode($cookieParams);
    
    $cmds = [];
    $names = [];
    
    if ($t && isset($map[$t])) {
        foreach ($map[$t] as $g) {
            $cmds[] = "cd " . escapeshellarg($b) . " && PYTHONUNBUFFERED=1 NO_COLOR=1 " . escapeshellarg($uv) . " run main.py " . escapeshellarg("$u.$g.$t") . " 2>&1";
            $names[] = "$g.$t";
        }
    } else {
        foreach (['noon', 'evening'] as $g) {
            $cmds[] = "cd " . escapeshellarg($b) . " && PYTHONUNBUFFERED=1 NO_COLOR=1 " . escapeshellarg($uv) . " run main.py " . escapeshellarg("$u.$g") . " 2>&1";
            $names[] = $g;
        }
    }
    
    if (empty($cmds)) {
        echo "❌ 错误: 未找到可执行的任务\n";
        exit;
    }
    
    foreach ($cmds as $i => $c) {
        flush();
        
        $p = proc_open($c, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        
        if (is_resource($p)) {
            fclose($pp[0]);
            stream_set_blocking($pp[1], 0);
            stream_set_blocking($pp[2], 0);
            $last = time();
            
            while (1) {
                $r = [];
                if (!feof($pp[1])) $r[] = $pp[1];
                if (!feof($pp[2])) $r[] = $pp[2];
                
                if (empty($r)) {
                    if (!proc_get_status($p)['running']) break;
                    if (time() - $last >= 5) {
                        echo " ";
                        flush();
                        $last = time();
                    }
                    usleep(50000);
                    continue;
                }
                
                $ch = stream_select($r, $w = null, $e = null, 0, 200000);
                if ($ch === false) break;
                
                foreach ($r as $s) {
                    $d = fread($s, 8192);
                    if ($d !== '' && $d !== false) {
                        echo preg_replace('/\x1b\[[0-9;]*m/', '', $d);
                        flush();
                        $last = time();
                    }
                }
            }
            
            fclose($pp[1]);
            fclose($pp[2]);
            proc_close($p);
            flush();
        } else {
            echo "❌ 错误: 无法启动进程 {$names[$i]}\n";
            flush();
        }
    }
    exit;
}

// 密码验证（兼容旧版）
$p = '9335521ww';
$login = isset($_SESSION['login']) && $_SESSION['login'] === true;
if (!$login && isset($_POST['pwd']) && $_POST['pwd'] === $p) {
    $_SESSION['login'] = true;
    $_SESSION['user_id'] = $user_id;
    $login = true;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Q宠大乐斗助手</title>
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            background: linear-gradient(135deg, #e6f7ff, #e6fff9);
            color: #2d3748;
            font-family: -apple-system, system-ui, "PingFang SC", "Helvetica Neue", sans-serif;
            padding: 12px;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -webkit-text-size-adjust: 100%;
        }
        .c { max-width: 1080px; margin: 0 auto; padding: 0 4px; }
        .h { text-align: center; padding: 8px 0 16px; }
        .h h1 { font-size: clamp(20px, 5vw, 24px); font-weight: 600; color: #234e52; }
        .h p { color: #8899aa; font-size: clamp(12px, 3vw, 13px); margin-top: 2px; }
        .user-info-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #e8f5e9;
            padding: 8px 16px;
            border-radius: 12px;
            margin-bottom: 12px;
            font-size: 13px;
            color: #2e7d32;
        }
        .user-info-bar .logout-btn {
            background: #ef5350;
            color: white;
            border: none;
            padding: 4px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
        }
        .user-info-bar .logout-btn:hover { background: #d32f2f; }
        .btn-reset {
            background: #ff9800;
            color: white;
            border: none;
            padding: 4px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 4px;
        }
        .btn-reset:hover { background: #e68900; }
        .btn-add {
            background: #2196f3;
            color: white;
            border: none;
            padding: 4px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 4px;
        }
        .btn-add:hover { background: #1976d2; }
        .btn-config {
            background: #9c27b0;
            color: white;
            border: none;
            padding: 4px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 4px;
        }
        .btn-config:hover { background: #7b1fa2; }
        .cd {
            background: #eef8fc;
            border-radius: 16px;
            padding: 14px 12px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(130, 170, 190, .12);
            border: 1px solid #c6e1f2;
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
        }
        .rw { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .ci { flex: 1; min-width: 100%; }
        @media(min-width:640px) { .ci { min-width: calc(50% - 5px); } .cd { padding: 18px; } body { padding: 16px; } }
        label { display: block; margin-bottom: 4px; color: #374151; font-weight: 500; font-size: clamp(12px, 3vw, 13px); }
        .sb {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #b4d7ec;
            border-radius: 12px;
            background: #e4f0f7;
            font-size: clamp(15px, 4vw, 16px);
            color: #2d3748;
            outline: none;
            transition: .15s;
            min-height: 42px;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2360c5d8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 36px;
        }
        .sb:focus { border-color: #60c5d8; box-shadow: 0 0 0 3px rgba(96, 197, 216, .2); }
        select.sb { cursor: pointer; }
        select.sb::-ms-expand { display: none; }
        #task {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #b4d7ec;
            border-radius: 12px;
            background: #e4f0f7;
            font-size: clamp(15px, 4vw, 16px);
            color: #2d3748;
            outline: none;
            transition: .15s;
            max-height: 420px;
            min-height: 42px;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2360c5d8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 36px;
        }
        #task:focus { border-color: #60c5d8; box-shadow: 0 0 0 3px rgba(96, 197, 216, .2); }
        #task option { padding: 8px 6px; font-size: 15px; }
        #logBox {
            width: 100%;
            min-height: 300px;
            padding: 12px;
            border: 1px solid #b4d7ec;
            border-radius: 12px;
            background: #1a1a2e;
            color: #00ff88;
            font-family: "SF Mono", Menlo, Consolas, "Courier New", monospace;
            font-size: clamp(12px, 3vw, 13px);
            line-height: 1.7;
            white-space: pre-wrap;
            word-break: break-word;
            resize: none;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
        }
        #logBox::-webkit-scrollbar { width: 4px; }
        #logBox::-webkit-scrollbar-track { background: #1a1a2e; border-radius: 4px; }
        #logBox::-webkit-scrollbar-thumb { background: #4a4a6a; border-radius: 4px; }
        #logBox::-webkit-scrollbar-thumb:hover { background: #5a5a8a; }
        @media(max-width:720px) { #logBox { padding: 10px; font-size: 12px; min-height: 250px; } }
        .bg { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .btn {
            padding: 11px 16px;
            border: none;
            border-radius: 12px;
            font-size: clamp(15px, 4vw, 16px);
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            flex: 1;
            min-width: 80px;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .btn:active { transform: scale(.96); }
        .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .bp { background: linear-gradient(135deg, #60c59c, #43b889); color: #fff; }
        .bp:active:not(:disabled) { opacity: 0.85; }
        .bd { background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; display: none; }
        .bd:active:not(:disabled) { opacity: 0.85; }
        .tip {
            margin-top: 12px;
            padding: 10px 12px;
            background: #e4f0f7;
            border-radius: 12px;
            font-size: clamp(12px, 3vw, 13px);
            color: #4f5c6d;
            border: 1px solid #b4d7ec;
            line-height: 1.8;
            word-break: break-word;
        }
        .tip strong { color: #234e52; }
        .qg {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 80px), 1fr));
            gap: 6px;
            margin-top: 8px;
        }
        .qb {
            padding: 8px 3px;
            border: 1px solid #a6c9e2;
            border-radius: 8px;
            background: #dcebf7;
            color: #234e52;
            font-size: clamp(11px, 2.8vw, 12px);
            font-weight: 500;
            min-height: 34px;
            cursor: pointer;
            text-align: center;
            transition: all .15s;
            word-break: break-word;
            line-height: 1.3;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .qb:active { transform: scale(.94); background: #cbe0f3; }
        .qb.hidden { display: none !important; }
        @media(max-width:480px) { .qg { grid-template-columns: repeat(4, 1fr); gap: 4px; } .qb { font-size: 10px; padding: 6px 2px; min-height: 30px; } }
        .tb {
            display: inline-block;
            padding: 6px 14px;
            margin-top: 6px;
            border: 1px solid #a6c9e2;
            border-radius: 8px;
            background: #dcebf7;
            color: #234e52;
            font-size: clamp(11px, 2.8vw, 12px);
            cursor: pointer;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .tb:active { transform: scale(.96); }
        .sp {
            margin-top: 10px;
            padding: 10px 12px;
            background: #e4f0f7;
            border-radius: 12px;
            border: 1px solid #b4d7ec;
            display: none;
            max-height: 500px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .sp.show { display: block; }
        .sp .si {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 0;
            font-size: clamp(12px, 3vw, 13px);
            flex-wrap: wrap;
        }
        .sp .si select {
            padding: 6px 8px;
            border-radius: 8px;
            border: 1px solid #b4d7ec;
            background: #fff;
            font-size: clamp(13px, 3.5vw, 14px);
            flex: 1;
            min-width: 120px;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2360c5d8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 16px;
            padding-right: 32px;
        }
        .sp .si .il { color: #667788; font-weight: 500; min-width: 24px; font-size: clamp(12px, 3vw, 13px); }
        .sp .sv {
            padding: 6px 16px;
            margin-top: 6px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #60c59c, #43b889);
            color: #fff;
            font-size: clamp(13px, 3.5vw, 14px);
            font-weight: 500;
            cursor: pointer;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .sp .sv:active { transform: scale(.96); }
        .st {
            font-size: clamp(11px, 2.8vw, 12px);
            color: #8899aa;
            cursor: pointer;
            margin-left: 6px;
            text-decoration: underline;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .st:active { color: #234e52; }
        input, select, textarea, button { font-family: -apple-system, system-ui, "PingFang SC", "Helvetica Neue", sans-serif; }
        @supports (-webkit-touch-callout: none) { body { min-height: -webkit-fill-available; } .cd { -webkit-backdrop-filter: blur(10px); } }
        @media (prefers-color-scheme: dark) {
            body { background: linear-gradient(135deg, #1a2332, #1a2a2a); }
            .cd { background: rgba(30, 40, 50, 0.9); border-color: #2a3a4a; }
            .h h1 { color: #7aafc9; }
            .h p { color: #6a8a9a; }
            label { color: #9ab0c0; }
            .sb, #task { background: #1a2a3a; border-color: #2a4a5a; color: #d0e0e8; }
            .sb:focus, #task:focus { border-color: #60c5d8; }
            .tip { background: #1a2a3a; border-color: #2a4a5a; color: #8aaabc; }
            .tip strong { color: #7aafc9; }
            .qb { background: #1a2a3a; border-color: #2a4a5a; color: #8aaabc; }
            .qb:active { background: #2a3a4a; }
            .tb { background: #1a2a3a; border-color: #2a4a5a; color: #8aaabc; }
            .sp { background: #1a2a3a; border-color: #2a4a5a; }
            .sp .si select { background: #1a2a3a; border-color: #2a4a5a; color: #d0e0e8; }
            .st { color: #6a8a9a; }
            .st:active { color: #8aaabc; }
            .user-info-bar { background: #1a2a3a; color: #81c784; }
        }
        @media (max-width: 380px) { .cd { padding: 10px 8px; } .qg { grid-template-columns: repeat(3, 1fr); } }
    </style>
</head>
<body>
    <div class="c">
        <div class="h">
            <h1>Q宠大乐斗助手</h1>
            <p>实时任务执行面板</p>
        </div>
        
        <div class="user-info-bar">
            <span>👤 <?= htmlspecialchars($user_data["nickname"] ?: $user_data["username"]) ?> (<?= htmlspecialchars($user_data["username"]) ?>)</span>
            <div>
                <span style="margin-right:12px;color:#555;">账号数: <?= count($accounts) ?></span>
                <a href="login/login.html" style="color:#2e7d32;margin-right:12px;">🔄 切换账号</a>
                <button class="btn-config" onclick="openConfig()">⚙️ 配置</button>
                <button class="btn-reset" onclick="resetPassword()">🔑 重置密码</button>
                <button class="btn-add" onclick="addAccount()">➕ 添加账号</button>
                <button class="logout-btn" onclick="logout()">退出</button>
            </div>
        </div>
        
        <div class="cd">
            <div class="rw">
                <div class="ci">
                    <label>选择QQ号</label>
                    <select id="uin" class="sb">
                        <?php if (empty($accounts)): ?>
                            <option value="">-- 暂无QQ号，请扫码添加 --</option>
                        <?php else: ?>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= htmlspecialchars($acc['newuin']) ?>">
                                    <?= htmlspecialchars($acc['newuin']) ?>
                                    <?php if ($acc['nickname']): ?>
                                        (<?= htmlspecialchars($acc['nickname']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            
            <div class="rw">
                <div class="ci" style="min-width:100%;">
                    <label>选择任务</label>
                    <select id="task">
                        <option value="">-- 执行全部任务 (午间 → 晚间) --</option>
                        <optgroup label="☀️ 午间任务 (noon) - <?= count($n) ?>个">
                            <?php foreach ($n as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="🌙 晚间任务 (evening) - <?= count($e) ?>个">
                            <?php foreach ($e as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            </div>
            
            <div class="bg">
                <button class="btn bp" id="runBtn">▶ 执行任务</button>
                <button class="btn bd" id="stopBtn">⏹ 停止执行</button>
            </div>
            
            <div style="margin-top:12px;">
                <label style="margin-bottom:4px;color:#374151;font-size:clamp(12px,3vw,13px);font-weight:500;">
                    ⚡ 快速任务
                    <span style="color:#8899aa;font-weight:400;font-size:clamp(11px,2.8vw,12px);">(点击直接执行)</span>
                    <span class="st" id="settingsToggle">⚙️ 自定义</span>
                </label>
                
                <div class="sp" id="settingsPanel">
                    <form method="post" id="quickTasksForm" autocomplete="off">
                        <div style="margin-bottom:6px;color:#667788;font-size:clamp(12px,3vw,13px);">
                            选择显示在快速任务栏的12个任务（按顺序排列）：
                        </div>
                        <?php for ($i = 1; $i <= 12; $i++):
                            $s = $quick[$i - 1] ?? '';
                        ?>
                            <div class="si">
                                <span class="il">#<?= $i ?></span>
                                <select name="quick_tasks[]" class="task-select">
                                    <option value="">-- 请选择 --</option>
                                    <?php foreach ($all as $tn): ?>
                                        <option value="<?= htmlspecialchars($tn) ?>" <?= $tn === $s ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tn) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endfor; ?>
                        <button type="submit" name="save_quick_tasks" class="sv">💾 保存设置</button>
                        <?php if (isset($_GET['saved'])): ?>
                            <span style="color:#43b889;font-size:clamp(12px,3vw,13px);margin-left:10px;">✅ 已保存</span>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="qg" id="quickTaskGrid">
                    <?php foreach ($quick as $tn): ?>
                        <button class="qb" data-task="<?= htmlspecialchars($tn) ?>"><?= htmlspecialchars($tn) ?></button>
                    <?php endforeach; ?>
                    <?php for ($i = count($quick); $i < 12; $i++): ?>
                        <button class="qb" style="border-color:#d0d8e0;color:#99aabb;cursor:default;opacity:.6;" disabled>
                            未设置
                        </button>
                    <?php endfor; ?>
                </div>
                
                <?php $rem = array_diff($all, $quick); ?>
                <?php if (!empty($rem)): ?>
                    <div class="qg" id="extraTaskGrid" style="margin-top:4px;">
                        <?php foreach ($rem as $tn): ?>
                            <button class="qb extra-task" data-task="<?= htmlspecialchars($tn) ?>">
                                <?= htmlspecialchars($tn) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div style="text-align:center;margin-top:4px;">
                        <button class="tb" id="toggleQuickBtn">📂 展开更多 (<?= count($rem) ?>个)</button>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tip">
                <strong>执行规则：</strong><br>
                • 选择单个任务 → 自动匹配分组，只执行该任务<br>
                • 冲突任务（同时存在于午间和晚间）→ 先午间后晚间<br>
                • 不选择任务 → 执行全部任务（先午间后晚间）
            </div>
        </div>
        
        <div class="cd" id="logCard">
            <label style="display:block;margin-bottom:4px;color:#374151;font-size:clamp(12px,3vw,13px);font-weight:500;">
                📋 实时输出
            </label>
            <textarea id="logBox" readonly></textarea>
        </div>
    </div>
    
    <script>
        const n = <?= json_encode($n) ?>;
        const e = <?= json_encode($e) ?>;
        const map = <?= json_encode($map) ?>;
        const $t = $("#task");
        const $u = $("#uin");
        const $l = $("#logBox");
        const $r = $("#runBtn");
        const $s = $("#stopBtn");
        let run = 0,
            x = null,
            h = null,
            isOpen = 0,
            exp = 0;
            
        function openConfig() {
            window.open('config.php', '_blank', 'width=800,height=600');
        }

        function resetPassword() {
            if (!confirm("确定要重置密码吗？重置后需要使用新密码重新登录。")) return;
            const newPassword = prompt("请输入新密码（至少6位）：");
            if (!newPassword || newPassword.length < 6) {
                alert("密码至少6位");
                return;
            }
            const confirmPassword = prompt("请再次输入新密码：");
            if (newPassword !== confirmPassword) {
                alert("两次密码输入不一致");
                return;
            }
            fetch("login/api.php?action=resetPassword", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ password: newPassword })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    alert("✅ 密码重置成功，请重新登录");
                    localStorage.removeItem("qq_login");
                    window.location.href = "login/index.html";
                } else {
                    alert("❌ 重置失败: " + (data.error || "未知错误"));
                }
            })
            .catch(function(error) {
                alert("❌ 请求失败: " + error.message);
            });
        }

        function addAccount() {
            if (!confirm("将打开扫码页面添加新QQ号，是否继续？")) return;
            window.open("login/index.html?action=add", "_blank", "width=480,height=700");
        }

        function logout() {
            if (confirm('确定要退出登录吗？')) {
                const btn = document.querySelector('.logout-btn');
                if (btn) { btn.textContent = '退出中...'; btn.disabled = true; }
                
                fetch('login/api.php?action=logout', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    console.log('退出成功:', data);
                    localStorage.removeItem('qq_login');
                    window.location.replace('login/index.html?t=' + Date.now());
                })
                .catch(function(error) {
                    console.error('退出失败:', error);
                    localStorage.removeItem('qq_login');
                    window.location.replace('login/index.html?t=' + Date.now());
                });
            }
        }
        
        $("#settingsToggle").on("click", function(e) {
            e.preventDefault();
            isOpen = !isOpen;
            $("#settingsPanel").toggleClass("show", isOpen);
            $(this).text(isOpen ? "✕ 关闭" : "⚙️ 自定义");
        });
        
        const $tb = $("#toggleQuickBtn");
        const $ex = $("#extraTaskGrid .qb");
        if ($ex.length) $ex.addClass("hidden");
        $tb.on("click", function(e) {
            e.preventDefault();
            exp = !exp;
            $ex.toggleClass("hidden", !exp);
            $(this).text(exp ? "📂 收起" : "📂 展开更多 (" + $ex.length + "个)");
        });
        
        function scrollLog() {
            var el = document.getElementById('logCard');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        function stop() {
            if (x) { x.abort(); x = null; }
            if (h) { clearInterval(h); h = null; }
            run = 0;
            $r.prop("disabled", 0).text("▶ 执行任务");
            $s.hide();
            scrollLog();
        }
        
        function runTask(name) {
            if (run) return;
            
            console.log('执行任务:', name);
            
            // 验证任务是否存在
            if (name && name !== '') {
                if (!map[name]) {
                    var availableTasks = Object.keys(map).join(', ');
                    alert('任务 "' + name + '" 不存在\n\n可用任务:\n' + availableTasks);
                    return;
                }
                $t.val(name);
            }
            
            run = 1;
            $r.prop("disabled", 1).text("执行中...");
            $s.show();
            $l.val('');
            scrollLog();
            const d = new URLSearchParams();
            d.append('uin', $u.val());
            d.append('task', name || '');
            if (h) clearInterval(h);
            h = setInterval(function() {
                if (run) {
                    $.get("?act=ping", function() {});
                }
            }, 25000);
            const c = new AbortController();
            x = { abort: function() { c.abort(); } };
            fetch('?act=run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: d.toString(),
                signal: c.signal
            }).then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const red = r.body.getReader();
                const dec = new TextDecoder();
                let buf = '';
                
                function read() {
                    red.read().then(function({ done, value }) {
                        if (done) {
                            run = 0;
                            if (h) { clearInterval(h); h = null; }
                            $r.prop("disabled", 0).text("▶ 执行任务");
                            $s.hide();
                            x = null;
                            return;
                        }
                        buf += dec.decode(value, { stream: 1 });
                        var lines = buf.split('\n');
                        buf = lines.pop() || '';
                        for (var i = 0; i < lines.length; i++) {
                            if (lines[i]) {
                                $l.val($l.val() + lines[i] + '\n');
                                $l.scrollTop($l[0].scrollHeight);
                            }
                        }
                        read();
                    }).catch(function(err) {
                        if (err.name === 'AbortError') return;
                        if (run) { alert('请求失败: ' + err.message); }
                        run = 0;
                        if (h) { clearInterval(h); h = null; }
                        $r.prop("disabled", 0).text("▶ 执行任务");
                        $s.hide();
                        x = null;
                    });
                }
                read();
            }).catch(function(err) {
                if (err.name === 'AbortError') return;
                if (run) { alert('请求失败: ' + err.message); }
                run = 0;
                if (h) { clearInterval(h); h = null; }
                $r.prop("disabled", 0).text("▶ 执行任务");
                $s.hide();
                x = null;
            });
        }
        
        $s.on("click", function(e) {
            e.preventDefault();
            if (confirm("确定要停止当前执行的任务吗？")) stop();
        });
        
        $(".qb").on("click", function(e) {
            e.preventDefault();
            var name = $(this).data("task");
            if (!name) return;
            console.log('快捷按钮点击:', name);
            $t.val(name);
            runTask(name);
        });
        
        $r.on("click", function(e) {
            e.preventDefault();
            runTask($t.val());
        });
        
        $(document).on("keydown", function(e) {
            if (e.ctrlKey && e.key === 'c' && run && confirm("确定要停止当前执行的任务吗？")) stop();
        });
        
        $(window).on('beforeunload', function() {
            if (x) x.abort();
            if (h) clearInterval(h);
        });
    </script>
</body>
</html>