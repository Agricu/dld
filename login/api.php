<?php
/**
 * API - 扫码登录 + 用户注册
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();

require_once __DIR__ . '/../config/database.inc.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

define('YAML_FILE', __DIR__ . '/qq_cookies.yaml');
define('NODE_SCRIPT', __DIR__ . '/qq-login.js');

$action = $_GET['action'] ?? '';

// ========== Node.js 执行函数 ==========
function execNode($action, $params = [], $timeout = 30) {
    if (!file_exists(NODE_SCRIPT)) {
        return ['success' => false, 'error' => 'Node.js 脚本不存在'];
    }
    
    $cmd = 'timeout ' . $timeout . ' node ' . escapeshellarg(NODE_SCRIPT) . ' ' . escapeshellarg($action);
    foreach ($params as $key => $value) {
        $cmd .= ' --' . escapeshellarg($key) . '=' . escapeshellarg($value);
    }
    $cmd .= ' 2>&1';
    
    $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (!is_resource($process)) {
        return ['success' => false, 'error' => '无法启动进程'];
    }
    
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    
    foreach (explode("\n", $output) as $line) {
        if (strpos($line, 'RESULT:') === 0) {
            $json = substr($line, 7);
            $result = json_decode($json, true);
            if ($result) return $result;
        }
    }
    
    return ['success' => false, 'error' => '解析失败', 'output' => substr($output, 0, 200)];
}

// ========== YAML 操作 ==========
function loadAccountsFromYaml() {
    if (!file_exists(YAML_FILE)) return [];
    try {
        $content = file_get_contents(YAML_FILE);
        $accounts = [];
        $current = null;
        foreach (explode("\n", $content) as $line) {
            $t = trim($line);
            if ($t === 'DALEDOU_COOKIES:') continue;
            if ($t === '' || strpos($t, '#') === 0) continue;
            if (strpos($t, '- openId:') === 0) {
                if ($current) $accounts[] = $current;
                $current = [];
                if (preg_match('/openId:\s*"([^"]+)"/', $t, $m)) $current['openId'] = $m[1];
            } elseif (strpos($t, 'newuin:') === 0 && $current !== null) {
                if (preg_match('/newuin:\s*"([^"]+)"/', $t, $m)) $current['newuin'] = $m[1];
            } elseif (strpos($t, 'accessToken:') === 0 && $current !== null) {
                if (preg_match('/accessToken:\s*"([^"]+)"/', $t, $m)) $current['accessToken'] = $m[1];
            }
        }
        if ($current) $accounts[] = $current;
        return $accounts;
    } catch (Exception $e) {
        return [];
    }
}

function saveToYaml($openId, $newuin, $accessToken) {
    $yaml = "DALEDOU_COOKIES:\n";
    $yaml .= "  - openId: \"{$openId}\"\n";
    $yaml .= "    newuin: \"{$newuin}\"\n";
    $yaml .= "    accessToken: \"{$accessToken}\"\n";
    file_put_contents(YAML_FILE, $yaml);
}

// ========== 用户注册 ==========
function registerUser($db, $username, $password, $openId, $accessToken, $newuin, $nickname) {
    if (empty($username) || empty($password) || empty($openId) || empty($accessToken) || empty($newuin)) {
        return ['success' => false, 'error' => '参数不完整'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => '密码至少6位'];
    }
    
    try {
        // 检查主账户是否已存在
        $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => '该用户名已存在，请直接登录'];
        }
        
        // 检查QQ号是否已被其他账户绑定
        $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "accounts WHERE newuin = ? OR open_id = ?");
        $stmt->execute([$newuin, $openId]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => '该QQ号已被其他账户绑定'];
        }
        
        // 创建主账户
        $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "users (username, password, nickname) VALUES (?, ?, ?)");
        $stmt->execute([$username, sha1($password), $nickname]);
        $user_id = $db->lastInsertId();
        
        // 添加第一个QQ账号
        $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "accounts (user_id, open_id, newuin, access_token, nickname, sort_order) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$user_id, $openId, $newuin, $accessToken, $nickname]);
        
        // 默认快捷任务
        $default = ['元武阁', '镖行天下', '武器专精', '徽章馆', '帮派祭坛', '神装', '华山论剑', '好友', '侠侣', '群侠', '巅峰之战', '竞技场'];
        foreach ($default as $idx => $task) {
            try {
                $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "quick_tasks (user_id, task_name, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $task, $idx]);
            } catch (PDOException $e) {}
        }
        
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        
        setcookie('qq_login', json_encode(['username' => $username, 'newuin' => $newuin]), time() + 604800, '/');
        
        return [
            'success' => true,
            'message' => '注册成功',
            'user' => [
                'id' => $user_id,
                'username' => $username,
                'newuin' => $newuin,
                'nickname' => $nickname
            ]
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => '注册失败: ' . $e->getMessage()];
    }
}

// ========== 处理请求 ==========
switch ($action) {
    // ========== 获取二维码 ==========
    case 'start':
        echo json_encode(execNode('start'));
        break;
    
    // ========== 轮询扫码状态 ==========
    case 'status':
        $qrsig = $_GET['qrsig'] ?? '';
        if (empty($qrsig)) {
            echo json_encode(['success' => false, 'error' => '缺少 qrsig 参数']);
            break;
        }
        $result = execNode('poll', ['qrsig' => $qrsig]);
        if ($result['success'] && isset($result['status']) && $result['status'] === 'success') {
            if (isset($result['gameCookies'])) {
                $c = $result['gameCookies'];
                saveToYaml($c['openId'] ?? '', $c['newuin'] ?? '', $c['accessToken'] ?? '');
                $_SESSION['gameCookies'] = $c;
                $_SESSION['nickname'] = $result['nickname'] ?? '';
            }
        }
        echo json_encode($result);
        break;
    
    // ========== 用户注册（扫码后设置密码） ==========
    case 'register':
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $openId = $input['openId'] ?? '';
        $accessToken = $input['accessToken'] ?? '';
        $newuin = $input['newuin'] ?? '';
        $nickname = $input['nickname'] ?? $newuin;
        
        $result = registerUser($db, $username, $password, $openId, $accessToken, $newuin, $nickname);
        echo json_encode($result);
        break;
    
    // ========== 密码登录 ==========
    case 'login':
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'error' => '请输入用户名和密码']);
            break;
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? AND enabled = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode(['success' => false, 'error' => '账号不存在，请先扫码注册']);
                break;
            }
            
            if ($user['password'] !== sha1($password)) {
                echo json_encode(['success' => false, 'error' => '密码错误']);
                break;
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            setcookie('qq_login', json_encode(['username' => $user['username']]), time() + 604800, '/');
            
            echo json_encode([
                'success' => true,
                'message' => '登录成功',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nickname' => $user['nickname'] ?: $user['username']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => '登录失败: ' . $e->getMessage()]);
        }
        break;
    
    // ========== 检查登录状态 ==========
    case 'check':
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ? AND enabled = 1");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                if ($user) {
                    $stmt2 = $db->prepare("SELECT * FROM " . DB_PREFIX . "accounts WHERE user_id = ? AND enabled = 1 ORDER BY sort_order LIMIT 1");
                    $stmt2->execute([$user['id']]);
                    $account = $stmt2->fetch();
                    
                    echo json_encode([
                        'success' => true,
                        'loggedIn' => true,
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'nickname' => $user['nickname'] ?: $user['username']
                        ],
                        'account' => $account ? [
                            'openId' => $account['open_id'],
                            'accessToken' => $account['access_token'],
                            'newuin' => $account['newuin'],
                            'nickname' => $account['nickname']
                        ] : null
                    ]);
                    break;
                }
            } catch (PDOException $e) {}
        }
        
        if (isset($_COOKIE['qq_login'])) {
            $data = json_decode($_COOKIE['qq_login'], true);
            if ($data && isset($data['username'])) {
                try {
                    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? AND enabled = 1");
                    $stmt->execute([$data['username']]);
                    $user = $stmt->fetch();
                    if ($user) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        
                        echo json_encode([
                            'success' => true,
                            'loggedIn' => true,
                            'user' => [
                                'id' => $user['id'],
                                'username' => $user['username'],
                                'nickname' => $user['nickname'] ?: $user['username']
                            ]
                        ]);
                        break;
                    }
                } catch (PDOException $e) {}
            }
        }
        
        echo json_encode(['success' => true, 'loggedIn' => false]);
        break;
    
    // ========== 重置密码 ==========
    case 'resetPassword':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => '未登录']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';
        
        if (empty($password) || strlen($password) < 6) {
            echo json_encode(['success' => false, 'error' => '密码至少6位']);
            break;
        }
        
        try {
            $stmt = $db->prepare("UPDATE " . DB_PREFIX . "users SET password = ? WHERE id = ?");
            $stmt->execute([sha1($password), $_SESSION['user_id']]);
            echo json_encode(['success' => true, 'message' => '密码重置成功']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========== 添加子账号 ==========
    case 'addAccount':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => '未登录']);
            break;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $openId = $input['openId'] ?? '';
        $newuin = $input['newuin'] ?? '';
        $accessToken = $input['accessToken'] ?? '';
        $nickname = $input['nickname'] ?? '';
        
        if (empty($openId) || empty($newuin) || empty($accessToken)) {
            echo json_encode(['success' => false, 'error' => '参数不完整']);
            break;
        }
        
        try {
            // 检查是否已存在
            $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "accounts WHERE user_id = ? AND (newuin = ? OR open_id = ?)");
            $stmt->execute([$_SESSION['user_id'], $newuin, $openId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => '该QQ号已添加']);
                break;
            }
            
            // 获取最大排序值
            $stmt = $db->prepare("SELECT MAX(sort_order) FROM " . DB_PREFIX . "accounts WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $maxSort = $stmt->fetchColumn() ?? -1;
            
            // 插入账号
            $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "accounts (user_id, open_id, newuin, access_token, nickname, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $openId, $newuin, $accessToken, $nickname, $maxSort + 1]);
            
            echo json_encode(['success' => true, 'message' => '账号添加成功', 'newuin' => $newuin]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    // ========== 退出登录 ==========
    case 'logout':
        $_SESSION = [];
        session_destroy();
        setcookie('qq_login', '', time() - 3600, '/');
        setcookie(session_name(), '', time() - 3600, '/');
        if (file_exists(YAML_FILE)) unlink(YAML_FILE);
        echo json_encode(['success' => true, 'message' => '已退出']);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => '未知操作: ' . $action]);
        break;
}