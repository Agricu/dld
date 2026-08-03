<?php
/**
 * Q宠大乐斗助手 - 主安装程序
 * 功能：环境检测 + 数据库配置 + 创建表 + 导入默认数据
 * 完成后自动跳转到扫码登录页面
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('ROOT_PATH', __DIR__ . '/');
define('LOCK_FILE', ROOT_PATH . 'install.lock');
define('DB_CONFIG_FILE', ROOT_PATH . 'config/database.inc.php');
define('NOON_TASK_FILE', ROOT_PATH . 'src/tasks/noon.py');
define('EVENING_TASK_FILE', ROOT_PATH . 'src/tasks/evening.py');

if (file_exists(LOCK_FILE) && file_exists(DB_CONFIG_FILE)) {
    header('Location: login/index.html');
    exit;
}

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$errors = [];
$success = [];
$warnings = [];

// ============================================
// 从 Python 文件提取任务函数名
// ============================================
function extract_task_names($file_path) {
    if (!file_exists($file_path)) {
        return [];
    }
    
    $content = file_get_contents($file_path);
    $tasks = [];
    
    preg_match_all('/@register\s*\(\s*\)\s*async\s+def\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $content, $matches);
    
    foreach ($matches[1] as $name) {
        if (strpos($name, '_') !== 0) {
            $tasks[] = $name;
        }
    }
    
    return array_values(array_unique($tasks));
}

// ============================================
// 步骤1: 环境检测
// ============================================
if ($step == 1) {
    // PHP 版本检测
    if (version_compare(PHP_VERSION, '7.0.0', '<')) {
        $errors[] = "PHP 版本过低 (当前: " . PHP_VERSION . ")，需要 >= 7.0.0";
    }

    // ============================================
    // 1. PHP 扩展检测
    // ============================================
    $required_extensions = [
        'session'   => '会话支持 (Session)',
        'pdo_mysql' => 'MySQL PDO 驱动',
        'json'      => 'JSON 支持',
        'pcre'      => '正则表达式支持 (PCRE)'
    ];
    
    foreach ($required_extensions as $ext => $name) {
        if (!extension_loaded($ext)) {
            $errors[] = "PHP 扩展 '{$ext}' ({$name}) 未加载，请启用后重试。";
        } else {
            $success[] = "✅ PHP 扩展 {$ext} ({$name}) 已加载";
        }
    }

    // ============================================
    // 2. PHP 禁用函数检测（只检测 proc_open 相关）
    // ============================================
    $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
    $disabled_functions = array_filter($disabled_functions);
    
    $required_functions = [
        'proc_open'         => '进程控制 (proc_open)',
        'proc_close'        => '进程控制 (proc_close)',
        'proc_get_status'   => '进程状态获取 (proc_get_status)',
        'stream_select'     => '流选择 (stream_select)'
    ];
    
    foreach ($required_functions as $func => $name) {
        if (in_array($func, $disabled_functions)) {
            $errors[] = "PHP 函数 '{$func}' ({$name}) 已被禁用，请从 disable_functions 中移除。";
        } else {
            $success[] = "✅ PHP 函数 {$func} 可用";
        }
    }

    // ============================================
    // 3. 可选扩展检测
    // ============================================
    $optional_extensions = [
        'curl'      => 'cURL (网络请求)',
        'gd'        => 'GD (图像处理)',
        'mbstring'  => 'MBString (多字节字符串)',
        'zlib'      => 'Zlib (压缩)',
        'openssl'   => 'OpenSSL (加密)'
    ];
    
    foreach ($optional_extensions as $ext => $name) {
        if (!extension_loaded($ext)) {
            $warnings[] = "可选扩展 '{$ext}' ({$name}) 未加载，部分功能可能受限。";
        }
    }

    // ============================================
    // 4. PHP 配置检测
    // ============================================
    $required_settings = [
        'allow_url_fopen' => 'allow_url_fopen (允许打开远程文件)'
    ];
    
    foreach ($required_settings as $setting => $name) {
        if (!ini_get($setting)) {
            $warnings[] = "PHP 配置 '{$setting}' ({$name}) 未开启，可能影响部分功能。";
        }
    }

    $max_execution = ini_get('max_execution_time');
    if (intval($max_execution) < 30 && $max_execution != 0) {
        $warnings[] = "max_execution_time = {$max_execution} 秒，建议设置为 0 或 30 秒以上。";
    }

    $memory_limit = ini_get('memory_limit');
    if (intval($memory_limit) < 128 && $memory_limit !== '-1') {
        $warnings[] = "memory_limit = {$memory_limit}，建议设置为 128M 或更高。";
    }

    $upload_max = ini_get('upload_max_filesize');
    $post_max = ini_get('post_max_size');
    if (intval($upload_max) < 8) {
        $warnings[] = "upload_max_filesize = {$upload_max}，建议设置为 8M 或更高。";
    }
    if (intval($post_max) < 8) {
        $warnings[] = "post_max_size = {$post_max}，建议设置为 8M 或更高。";
    }

    // ============================================
    // 5. 检测 Python
    // ============================================
    $python_output = [];
    $python_result = 0;
    @exec("/usr/bin/python3 --version 2>&1", $python_output, $python_result);
    if ($python_result !== 0) {
        $warnings[] = "Python3 未安装或不在 PATH 中，任务执行将无法工作。";
    } else {
        $success[] = "Python3: " . trim(implode(' ', $python_output));
    }

    // ============================================
    // 6. 检测 uv
    // ============================================
    $uv_output = [];
    $uv_result = 0;
    @exec("/usr/local/bin/uv --version 2>&1", $uv_output, $uv_result);
    if ($uv_result !== 0) {
        $warnings[] = "uv 包管理器未安装，请执行: curl -LsSf https://astral.sh/uv/install.sh | sh";
    } else {
        $success[] = "uv: " . trim(implode(' ', $uv_output));
    }

    // ============================================
    // 7. 检测 Node.js
    // ============================================
    $node_output = [];
    $node_result = 0;
    @exec("/usr/bin/node --version 2>&1", $node_output, $node_result);
    if ($node_result !== 0) {
        @exec("node --version 2>&1", $node_output, $node_result);
    }
    if ($node_result !== 0) {
        $warnings[] = "Node.js 未安装或不在 PATH 中，扫码登录将无法工作。";
    } else {
        $success[] = "Node.js: " . trim(implode(' ', $node_output));
    }

    // ============================================
    // 8. 检测目录权限
    // ============================================
    $dirs = ['config', 'logs', 'cache', 'login'];
    foreach ($dirs as $dir) {
        $path = ROOT_PATH . $dir;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                $errors[] = "无法创建目录: {$path}，请手动创建并设置权限 0755。";
            } else {
                $success[] = "目录 {$dir} 已创建。";
            }
        } elseif (!is_writable($path)) {
            $warnings[] = "目录 {$path} 不可写，请设置权限为 0755。";
        } else {
            $success[] = "目录 {$dir} 权限正确 (可写)";
        }
    }

    // ============================================
    // 9. 检测 PHP 错误日志配置
    // ============================================
    $error_log = ini_get('error_log');
    if ($error_log) {
        $log_dir = dirname($error_log);
        if (is_writable($log_dir) || is_writable(dirname($log_dir))) {
            $success[] = "错误日志配置正常: {$error_log}";
        } else {
            $warnings[] = "错误日志目录不可写: {$log_dir}";
        }
    } else {
        $warnings[] = "未配置 error_log，建议配置以便调试";
    }
}

// ============================================
// 步骤2: 数据库配置
// ============================================
if ($step == 2) {
    $db_config = [
        'host' => $_POST['db_host'] ?? 'localhost',
        'port' => $_POST['db_port'] ?? '3306',
        'name' => $_POST['db_name'] ?? '',
        'user' => $_POST['db_user'] ?? '',
        'pass' => $_POST['db_pass'] ?? '',
        'prefix' => $_POST['db_prefix'] ?? 'qpet_'
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_db'])) {
        if (empty($db_config['name'])) {
            $errors[] = "请输入数据库名称";
        }
        if (empty($db_config['user'])) {
            $errors[] = "请输入数据库用户名";
        }
        
        if (empty($errors)) {
            try {
                $dsn = "mysql:host={$db_config['host']};port={$db_config['port']}";
                $pdo = new PDO($dsn, $db_config['user'], $db_config['pass']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $dbname = $db_config['name'];
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` 
                            CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $pdo->exec("USE `{$dbname}`");
                
                $success[] = "✅ 数据库连接成功，已连接到: {$dbname}";
                
                $prefix = $db_config['prefix'];
                
                // 1. 主账户表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}users` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL COMMENT '主账户用户名',
                    `password` varchar(255) NOT NULL COMMENT '密码(SHA1)',
                    `nickname` varchar(100) DEFAULT NULL,
                    `enabled` tinyint(1) DEFAULT 1,
                    `last_login` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}users 创建成功";

                // 2. QQ账号表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}accounts` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL COMMENT '所属主账户ID',
                    `open_id` varchar(64) NOT NULL COMMENT 'QQ OpenID',
                    `access_token` varchar(64) NOT NULL COMMENT 'QQ AccessToken',
                    `newuin` varchar(20) NOT NULL COMMENT 'QQ号',
                    `nickname` varchar(100) DEFAULT NULL,
                    `enabled` tinyint(1) DEFAULT 1,
                    `sort_order` int(11) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_user_newuin` (`user_id`, `newuin`),
                    UNIQUE KEY `idx_user_openid` (`user_id`, `open_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}accounts 创建成功";

                // 3. 默认配置表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}default_configs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `config_group` enum('noon','evening') NOT NULL COMMENT '配置分组',
                    `config_key` varchar(255) NOT NULL COMMENT '配置键路径',
                    `config_value` text DEFAULT NULL COMMENT '配置值（JSON格式）',
                    `value_type` enum('string','int','bool','array','object','null') DEFAULT 'string' COMMENT '值类型',
                    `description` varchar(255) DEFAULT NULL COMMENT '配置说明',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_default_config` (`config_group`, `config_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}default_configs 创建成功";

                // 4. 子账号配置表（原 user_configs 改为 account_configs，关联 account_id）
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}account_configs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `account_id` int(11) NOT NULL COMMENT 'QQ账号ID',
                    `config_group` enum('noon','evening') NOT NULL COMMENT '配置分组',
                    `config_key` varchar(255) NOT NULL COMMENT '配置键路径',
                    `config_value` text DEFAULT NULL COMMENT '配置值（JSON格式）',
                    `value_type` enum('string','int','bool','array','object','null') DEFAULT 'string',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_account_config` (`account_id`, `config_group`, `config_key`),
                    KEY `idx_account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}account_configs 创建成功";

                // 5. 任务表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}tasks` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `task_name` varchar(100) NOT NULL COMMENT '任务名称',
                    `task_group` enum('noon','evening','both') DEFAULT 'both' COMMENT '任务分组',
                    `enabled` tinyint(1) DEFAULT 1 COMMENT '是否启用',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_task_name` (`task_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}tasks 创建成功";

                // 6. 子账号快捷任务表（原 quick_tasks 改为 account_quick_tasks，关联 account_id）
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}account_quick_tasks` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `account_id` int(11) NOT NULL COMMENT 'QQ账号ID',
                    `task_name` varchar(100) NOT NULL COMMENT '任务名称',
                    `sort_order` int(11) DEFAULT 0 COMMENT '排序',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_account_task` (`account_id`, `task_name`),
                    KEY `idx_account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}account_quick_tasks 创建成功";

                // 7. 任务日志表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}task_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL COMMENT '用户ID',
                    `account_id` int(11) NOT NULL COMMENT 'QQ账号ID',
                    `task_group` enum('noon','evening') NOT NULL COMMENT '任务分组',
                    `task_name` varchar(100) NOT NULL COMMENT '任务名称',
                    `status` enum('success','failed','running','skipped') DEFAULT 'running',
                    `output` text DEFAULT NULL COMMENT '执行输出',
                    `execution_time` float DEFAULT NULL COMMENT '执行耗时(秒)',
                    `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `finished_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_task` (`user_id`, `task_name`),
                    KEY `idx_account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}task_logs 创建成功";

                // 8. 系统设置表
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}system_settings` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `setting_key` varchar(100) NOT NULL COMMENT '设置键',
                    `setting_value` text NOT NULL COMMENT '设置值',
                    `description` varchar(255) DEFAULT NULL COMMENT '描述',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $success[] = "✅ 表 {$prefix}system_settings 创建成功";

                // ============================================
                // 从 noon.py 和 evening.py 动态获取任务列表
                // ============================================
                $noon_tasks = extract_task_names(NOON_TASK_FILE);
                $evening_tasks = extract_task_names(EVENING_TASK_FILE);
                
                $all_tasks = [];
                foreach ($noon_tasks as $task) {
                    $all_tasks[] = ['task_name' => $task, 'task_group' => 'noon'];
                }
                foreach ($evening_tasks as $task) {
                    $exists_in_noon = in_array($task, $noon_tasks);
                    $group = $exists_in_noon ? 'both' : 'evening';
                    $all_tasks[] = ['task_name' => $task, 'task_group' => $group];
                }

                $taskCount = 0;
                foreach ($all_tasks as $task) {
                    try {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO {$prefix}tasks (task_name, task_group) VALUES (?, ?)");
                        $stmt->execute([$task['task_name'], $task['task_group']]);
                        $taskCount++;
                    } catch (PDOException $e) {}
                }
                $success[] = "✅ 已导入 {$taskCount} 个默认任务";

                // ============================================
                // 导入默认配置（全局默认值，供新账号复制）
                // ============================================
                $defaultConfigs = [
                    ['noon', '元武阁.enabled', '0', 'int', '1=执行开箱和领取, 0=不执行'],
                    ['noon', '镖行天下.拦截最大战力', '8500', 'int', '拦截最大战力阈值'],
                    ['noon', '武器专精.type_id', '0', 'int', '0=投掷武器, 1=小型, 2=中型, 3=大型'],
                    ['noon', '武器专精.ten_times', 'false', 'bool', 'true=十连升, false=单次升级'],
                    ['noon', '徽章馆.badge_id', '1', 'int', '徽章ID: 1=勤劳徽章'],
                    ['noon', '帮派祭坛.exchange', '{"1260":{"material_name":"帮派战鼓","quantity":0},"1261":{"material_name":"奥秘元素","quantity":1000},"1262":{"material_name":"四灵魂石","quantity":0}}', 'object', '帮派祭坛兑换配置'],
                    ['noon', '神装.magic_outfit_ids', '[0]', 'array', '神装ID列表: 0=神兵'],
                    ['noon', '华山论剑.战阵调整', '[{"count":8,"knights":["金轮法王","郭靖","欧阳锋"]},{"count":0,"knights":["龙岛主","木岛主","斗酒僧"]}]', 'array', '战阵调整配置'],
                    ['noon', '华山论剑.exchange', '{"6857":{"material_name":"钻石","quantity":375},"6347":{"material_name":"侠士修身录","quantity":0},"6348":{"material_name":"侠士手记","quantity":0},"6349":{"material_name":"重出江湖令","quantity":0},"3090":{"material_name":"斗神符","quantity":0}}', 'object', '华山论剑兑换配置'],
                    ['noon', '好友.贡献药水.count', '4', 'int', '贡献药水使用次数'],
                    ['noon', '侠侣.情师徒拜.enabled', 'true', 'bool', '是否执行情师徒拜'],
                    ['noon', '群侠.设置战队', '["笑红尘","石破天","王重阳","木岛主","龙岛主"]', 'array', '群侠战队配置'],
                    ['noon', '巅峰之战进行中.id', '0', 'int', '0=随机加入, 1=南派, 2=北派'],
                    ['noon', '矿洞.floor', '1', 'int', '矿洞层数: 1-5'],
                    ['noon', '矿洞.mode', '1', 'int', '矿洞模式: 1=简单, 2=普通, 3=困难'],
                    ['noon', '竞技场.河图洛书.enabled', 'true', 'bool', '是否启用河图洛书'],
                    ['noon', '竞技场.神秘精华.enabled', 'false', 'bool', '是否启用神秘精华'],
                    ['noon', '竞技场.神兵原石.enabled', 'false', 'bool', '是否启用神兵原石'],
                    ['noon', '竞技场.软猥金丝.enabled', 'false', 'bool', '是否启用软猥金丝'],
                    ['noon', '竞技场.精武之魂.enabled', 'false', 'bool', '是否启用精武之魂'],
                    ['noon', '竞技场.守护之魂.enabled', 'false', 'bool', '是否启用守护之魂'],
                    ['noon', '十二宫.id', '1011', 'int', '十二宫ID: 1011=双鱼宫'],
                    ['noon', '历练', '{"6134":3,"6394":3}', 'object', '历练BOSS配置'],
                    ['noon', '历练.6134', '3', 'int', '洞庭湖-大鹏 扫地僧碎片'],
                    ['noon', '历练.6394', '3', 'int', '鹅王的试炼-全副武装的鹅王'],
                    ['noon', '幻境.id', '20', 'int', '幻境ID: 20=鹅王的试炼'],
                    ['noon', '幻境.exchange', '{"1235":10}', 'object', '幻境兑换配置'],
                    ['noon', '门派.门派高香.enabled', 'false', 'bool', '是否启用门派高香'],
                    ['noon', '门派.门派战书.enabled', 'false', 'bool', '是否启用门派战书'],
                    ['noon', '门派邀请赛.exchange', '{"1246":{"material_name":"炼气石","quantity":10},"1247":{"material_name":"门派强化书","quantity":10},"1248":{"material_name":"门派高香","quantity":0},"1249":{"material_name":"门派战书","quantity":0},"1250":{"material_name":"门派引荐书","quantity":0}}', 'object', '门派邀请赛兑换配置'],
                    ['noon', '会武.试炼书.enabled', 'true', 'bool', '试炼场试炼书不足时兑换'],
                    ['noon', '会武.exchange', '{"1263":{"material_name":"真黄金卷轴","quantity":20},"1264":{"material_name":"修为丹","quantity":0}}', 'object', '会武兑换配置'],
                    ['noon', '梦想之旅.梦幻旅行.count', '5', 'int', '梦幻旅行次数阈值'],
                    ['noon', '问鼎天下.region', '3', 'int', '1=东海, 2=南荒, 3=西泽, 4=北寒'],
                    ['noon', '问鼎天下.count', '2', 'int', '攻占1级资源点次数'],
                    ['noon', '问鼎天下.淘汰赛', '96690', 'int', '周六助威帮派ID'],
                    ['noon', '问鼎天下.排名赛', '96690', 'int', '周日助威帮派ID'],
                    ['noon', '帮派商会.交易会所', '["体力","经验","百炼钢","黄金卷轴","神兵原石","软猬金丝","凤凰羽毛","潜能果实","上古玉髓","奔流气息","大型武器符文石"]', 'array', '交易会所物品列表'],
                    ['noon', '帮派商会.兑换商店', '["泯灭·碧玉V碎片","破坏·锦月V碎片"]', 'array', '兑换商店物品列表'],
                    ['noon', '武林盟主.id', 'NULL', 'null', '1=黄金赛场, 2=白银赛场, 3=青铜赛场'],
                    ['noon', '侠士客栈.黑市商人', '["消耗斗灵石-空*66换取V级万能碎片*1","消耗黄金卷轴*3换取斗神符*1","消耗挑战书*2换取玄铁令*1","消耗斗神符*1换取淬火结晶*1","消耗无字天书*1换取易经八卦*1"]', 'array', '黑市商人交换列表'],
                    ['noon', '江湖长梦.exchange', '{"0":{"material_name":"侠士修身录","quantity":0},"1":{"material_name":"侠士手记","quantity":0},"5":{"material_name":"玄铁令","quantity":0},"6":{"material_name":"淬火结晶","quantity":0},"10":{"material_name":"真黄金卷轴","quantity":0},"18":{"material_name":"石中剑","quantity":100},"19":{"material_name":"大型武器符咒","quantity":0},"20":{"material_name":"中型武器符咒","quantity":0},"21":{"material_name":"小型武器符咒","quantity":0},"22":{"material_name":"投掷武器符咒","quantity":0}}', 'object', '江湖长梦兑换配置'],
                    ['noon', '飞升大作战.玄铁令.enabled', 'false', 'bool', '积分商城兑换玄铁令'],
                    ['noon', '飞升大作战.type', '1', 'int', '1=单排模式, 2=匹配模式, 3=双排模式'],
                    ['noon', '飞升大作战.id', '3', 'int', '备战天赋: 1=燃魂决基础, 2=燃魂决上层, 3=燃魂决顶层'],
                    ['noon', '深渊之潮.深渊秘境.count', '0', 'int', '兑换副本次数'],
                    ['noon', '深渊之潮.深渊秘境.id', '6', 'int', '副本ID: 6=吞厄源头'],
                    ['noon', '深渊之潮.exchange', '{"5":{"material_name":"气魄之书","quantity":0},"6":{"material_name":"力魄之书","quantity":0},"7":{"material_name":"精魄之书","quantity":0},"8":{"material_name":"英魄之书","quantity":0},"9":{"material_name":"中枢之书","quantity":100},"10":{"material_name":"天冲之书","quantity":0},"11":{"material_name":"灵慧之书","quantity":0},"12":{"material_name":"佣兵天赋丹","quantity":0},"13":{"material_name":"许愿卡","quantity":0},"14":{"material_name":"五星许愿卡碎片","quantity":0},"15":{"material_name":"豆油队长碎片","quantity":0},"16":{"material_name":"定海制作书","quantity":1}}', 'object', '深渊之潮兑换配置'],
                    ['noon', '侠客岛.侠客行', '["侠士修身录*2","侠士手记*2","太玄经*2","太玄经*1","玄铁令*1"]', 'array', '侠客行奖励列表'],
                    ['noon', '时空遗迹.异兽洞窟', '[5,4,3,2,1]', 'array', '异兽洞窟优先级列表'],
                    ['noon', '时空遗迹.exchange', '{"4":{"material_name":"舆图","quantity":40,"type":1},"8":{"material_name":"舆图","quantity":20,"type":2},"1":{"material_name":"日引石","quantity":100,"type":1},"2":{"material_name":"月引石","quantity":100,"type":1},"3":{"material_name":"星引石","quantity":100,"type":1},"5":{"material_name":"日引石","quantity":50,"type":2},"6":{"material_name":"月引石","quantity":50,"type":2},"7":{"material_name":"星引石","quantity":50,"type":2}}', 'object', '时空遗迹兑换配置'],
                    ['noon', '龙凰之境.龙凰论武.id', '1', 'int', '1=龙渊赛区, 2=凰极赛区'],
                    ['noon', '龙凰之境.龙凰论武.count', '1', 'int', '挑战玩家次数'],
                    ['noon', '龙凰之境.exchange', '{"1":{"material_name":"论武券","quantity":0},"2":{"material_name":"龙玉","quantity":0},"3":{"material_name":"凰髓","quantity":100},"4":{"material_name":"凰火","quantity":0}}', 'object', '龙凰之境兑换配置'],
                    ['noon', '我的帮派.帮战.enabled', 'false', 'bool', '周日报名帮战'],
                    ['noon', '我的帮派.帮派供奉', '[3871]', 'array', '帮派供奉物品ID列表'],
                    ['noon', '客栈同福.献酒', '["王重阳碎片","王处一碎片"]', 'array', '献酒奖励列表'],
                    ['noon', '节日福利.历练.enabled', 'true', 'bool', '每天反向乐斗BOSS'],
                    ['noon', '双旦福利.历练.enabled', 'true', 'bool', '每天反向乐斗BOSS'],
                    ['noon', '金秋福利.历练.enabled', 'true', 'bool', '每天反向乐斗BOSS'],
                    ['noon', '春节福利.历练.enabled', 'true', 'bool', '每天反向乐斗BOSS'],
                    ['noon', '多倍福利.历练.enabled', 'true', 'bool', '每天反向乐斗BOSS'],
                    
                    ['evening', '帮派商会.交易会所', '["体力","经验","百炼钢","黄金卷轴","神兵原石","软猬金丝","凤凰羽毛","潜能果实","上古玉髓","奔流气息","大型武器符文石"]', 'array', '交易会所物品列表'],
                    ['evening', '帮派商会.兑换商店', '["泯灭·碧玉V碎片","破坏·锦月V碎片"]', 'array', '兑换商店物品列表'],
                    ['evening', '侠士客栈.黑市商人', '["消耗斗灵石-空*66换取V级万能碎片*1","消耗黄金卷轴*3换取斗神符*1","消耗挑战书*2换取玄铁令*1","消耗斗神符*1换取淬火结晶*1","消耗无字天书*1换取易经八卦*1"]', 'array', '黑市商人交换列表'],
                    ['evening', '江湖长梦.柒承的忙碌日常.enabled', 'true', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.柒承的忙碌日常.material_name', '追忆香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.柒承的忙碌日常.material_id', '6477', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.群英拭剑谁为峰.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.群英拭剑谁为峰.material_name', '拭剑香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.群英拭剑谁为峰.material_id', '6940', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.时空守护者.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.时空守护者.material_name', '时空香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.时空守护者.material_id', '6532', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.倚天屠龙归我心.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.倚天屠龙归我心.material_name', '九阳香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.倚天屠龙归我心.material_id', '6904', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.神雕侠侣.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.神雕侠侣.material_name', '盛世香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.神雕侠侣.material_id', '6476', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.雪山藏魂.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.雪山藏魂.material_name', '雪山香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.雪山藏魂.material_id', '8121', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.桃花自古笑春风.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.桃花自古笑春风.material_name', '桃花香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.桃花自古笑春风.material_id', '6825', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.战乱襄阳.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.战乱襄阳.material_name', '忠义香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.战乱襄阳.material_id', '6888', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.天涯浪子.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.天涯浪子.material_name', '中秋香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.天涯浪子.material_id', '6547', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.全真古墓意难平.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.全真古墓意难平.material_name', '全真香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.全真古墓意难平.material_id', '6662', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.南海有岛名侠客.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.南海有岛名侠客.material_name', '海岛香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.南海有岛名侠客.material_id', '6982', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.老鹅的圣诞冒险.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.老鹅的圣诞冒险.material_name', '圣诞香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.老鹅的圣诞冒险.material_id', '6609', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.世外桃源梦一场.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.世外桃源梦一场.material_name', '回梦香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.世外桃源梦一场.material_id', '6855', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.中原武林之危.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.中原武林之危.material_name', '大宴香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.中原武林之危.material_id', '6773', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.世界树轮回秘境.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.世界树轮回秘境.material_name', '树境香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.世界树轮回秘境.material_id', '8222', 'int', '消耗材料ID'],
                    ['evening', '江湖长梦.技冠五绝.enabled', 'false', 'bool', '是否启用该副本'],
                    ['evening', '江湖长梦.技冠五绝.material_name', '五绝香炉', 'string', '消耗材料名称'],
                    ['evening', '江湖长梦.技冠五绝.material_id', '8604', 'int', '消耗材料ID'],
                    ['evening', '深渊之潮.深渊秘境.count', '0', 'int', '兑换副本次数'],
                    ['evening', '深渊之潮.深渊秘境.id', '6', 'int', '副本ID: 6=吞厄源头'],
                    ['evening', '龙凰之境.龙凰论武.count', '1', 'int', '随机挑战玩家次数'],
                    ['evening', '背包.使用', '["食盒","魂珠碎片宝箱","小宝箱","中宝箱","红包","武林宝箱","锦囊","的信物","周年福袋"]', 'array', '自动使用的物品关键词'],
                    ['evening', '吉利兑.exchange', '{"5":{"material_name":"V级万能碎片","quantity":25},"6":{"material_name":"熔炼乌金","quantity":100},"7":{"material_name":"玄铁令","quantity":0},"8":{"material_name":"挑战书","quantity":0}}', 'object', '吉利兑兑换配置'],
                    ['evening', '登录商店.id', '1225', 'int', '登录商店物品ID: 武穆遗书*1'],
                    ['evening', '儿童节.id', 'type=3&sub_type=1', 'string', '儿童节选择: 小型武器专精'],
                    ['evening', '开学季.id', 'type=3&sub_type=1', 'string', '开学季选择: 小型武器专精'],
                    ['evening', '生肖福卡.QQ', '643600871', 'string', '分享福卡的QQ号'],
                    ['evening', '客栈同福.献酒', '["王重阳碎片","王处一碎片"]', 'array', '献酒奖励列表'],
                    ['evening', '节日福利.斗神塔.count', '16', 'int', '斗神塔挑战次数'],
                    ['evening', '双旦福利.斗神塔.count', '16', 'int', '斗神塔挑战次数'],
                    ['evening', '金秋福利.斗神塔.count', '16', 'int', '斗神塔挑战次数'],
                    ['evening', '春节福利.斗神塔.count', '16', 'int', '斗神塔挑战次数'],
                    ['evening', '多倍福利.斗神塔.count', '16', 'int', '斗神塔挑战次数'],
                    ['evening', '兑换码', '161616', 'string', '兑换码'],
                    ['evening', '春联大赛.爆竹传吉语', '腊梅报新春', 'string', '春联配对'],
                    ['evening', '春联大赛.爆竹欣祝福', '银蛇喜迎春', 'string', '春联配对'],
                    ['evening', '春联大赛.爆竹欣祝褔', '银蛇乐报春', 'string', '春联配对'],
                    ['evening', '春联大赛.春呈丰稔景', '酒贺小龙年', 'string', '春联配对'],
                    ['evening', '春联大赛.春到田畴绿', '蛇来淑景新', 'string', '春联配对'],
                    ['evening', '春联大赛.春归蛇起舞', '福到鸟争鸣', 'string', '春联配对'],
                    ['evening', '春联大赛.春来千野绿', '蛇舞四时新', 'string', '春联配对'],
                    ['evening', '春联大赛.除牙难捕鼠', '添足便成龙', 'string', '春联配对'],
                    ['evening', '春联大赛.丁年歌盛世', '卯兔耀中华', 'string', '春联配对'],
                    ['evening', '春联大赛.国强民幸福', '蛇舞世升平', 'string', '春联配对'],
                    ['evening', '春联大赛.红梅迎春笑', '玉兔出月欢', 'string', '春联配对'],
                    ['evening', '春联大赛.红梅迎雪放', '玉兔踏春来', 'string', '春联配对'],
                    ['evening', '春联大赛.红梅赠虎岁', '彩烛耀兔年', 'string', '春联配对'],
                    ['evening', '春联大赛.花放山河丽', '蛇迎世纪春', 'string', '春联配对'],
                    ['evening', '春联大赛.花开四季馥', '蛇舞九州春', 'string', '春联配对'],
                    ['evening', '春联大赛.花柳春风绿', '蛇年瑞气盈', 'string', '春联配对'],
                    ['evening', '春联大赛.虎辟长安道', '兔开大吉春', 'string', '春联配对'],
                    ['evening', '春联大赛.虎驰金世界', '兔唤玉乾坤', 'string', '春联配对'],
                    ['evening', '春联大赛.虎带祥云去', '兔铺锦绣来', 'string', '春联配对'],
                    ['evening', '春联大赛.虎年腾大步', '兔岁展宏图', 'string', '春联配对'],
                    ['evening', '春联大赛.虎去雄风在', '兔来喜气浓', 'string', '春联配对'],
                    ['evening', '春联大赛.虎声传捷报', '兔影抖春晖', 'string', '春联配对'],
                    ['evening', '春联大赛.虎嘶飞雪里', '兔舞画图中', 'string', '春联配对'],
                    ['evening', '春联大赛.虎蹄留胜迹', '兔角搏青云', 'string', '春联配对'],
                    ['evening', '春联大赛.虎威惊盛世', '兔翰绘新春', 'string', '春联配对'],
                    ['evening', '春联大赛.虎跃前程去', '兔携好运来', 'string', '春联配对'],
                    ['evening', '春联大赛.虎留英雄气', '兔会世纪风', 'string', '春联配对'],
                    ['evening', '春联大赛.捷报飞新宇', '春潮促小龙', 'string', '春联配对'],
                    ['evening', '春联大赛.捷报书宏志', '春风乐小龙', 'string', '春联配对'],
                    ['evening', '春联大赛.金虎辞旧岁', '银兔贺新春', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇含瑞草', '紫燕报新春', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇狂舞日', '紫燕报春时', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇盘玉兔', '赤帜舞神州', 'string', '春联配对'],
                    ['evening', '春联大赛.睛点龙飞去', '珠还蛇舞来', 'string', '春联配对'],
                    ['evening', '春联大赛.龙去神威在', '蛇来紫气生', 'string', '春联配对'],
                    ['evening', '春联大赛.龙蛇交替舞', '岁月又更新', 'string', '春联配对'],
                    ['evening', '春联大赛.龙腾传捷报', '蛇舞兆丰年', 'string', '春联配对'],
                    ['evening', '春联大赛.龙腾丰稔岁', '蛇舞吉庆年', 'string', '春联配对'],
                    ['evening', '春联大赛.龙舞山河壮', '蛇盘世纪新', 'string', '春联配对'],
                    ['evening', '春联大赛.龙吟山海壮', '蛇舞国民欢', 'string', '春联配对'],
                    ['evening', '春联大赛.龙展强邦志', '蛇生富国情', 'string', '春联配对'],
                    ['evening', '春联大赛.卯来四季美', '兔献百家福', 'string', '春联配对'],
                    ['evening', '春联大赛.卯门生紫气', '兔岁报拜年', 'string', '春联配对'],
                    ['evening', '春联大赛.卯时春入户', '兔岁喜盈门', 'string', '春联配对'],
                    ['evening', '春联大赛.民逢大有岁', '国正小龙年', 'string', '春联配对'],
                    ['evening', '春联大赛.瑞雪兆丰年', '迎得玉兔归', 'string', '春联配对'],
                    ['evening', '春联大赛.山舞银蛇景', '梅香瑞雪春', 'string', '春联配对'],
                    ['evening', '春联大赛.山舞银蛇日', '地披红杏时', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇酿新年酒', '花开盛世春', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇舞升平世', '莺歌富贵春', 'string', '春联配对'],
                    ['evening', '春联大赛.笙歌辞旧岁', '兔酒庆新春', 'string', '春联配对'],
                    ['evening', '春联大赛.兔归皓月亮', '花绽春光妍', 'string', '春联配对'],
                    ['evening', '春联大赛.兔毫抒壮志', '燕梭织春光', 'string', '春联配对'],
                    ['evening', '春联大赛.兔俊千山秀', '春暖万水清', 'string', '春联配对'],
                    ['evening', '春联大赛.喜迎新世纪', '欢庆小龙年', 'string', '春联配对'],
                    ['evening', '春联大赛.雪消狮子瘦', '月满兔儿肥', 'string', '春联配对'],
                    ['evening', '春联大赛.燕舞春光丽', '兔奔曙光新', 'string', '春联配对'],
                    ['evening', '春联大赛.寅年春锦绣', '卯序业辉煌', 'string', '春联配对'],
                    ['evening', '春联大赛.玉兔蟾宫笑', '红梅五岭香', 'string', '春联配对'],
                    ['evening', '春联大赛.玉兔迎春到', '红梅祝福来', 'string', '春联配对'],
                    ['evening', '春联大赛.玉兔迎春至', '黄莺报喜来', 'string', '春联配对'],
                    ['evening', '春联大赛.Q宠驭马闯新春', '侠士跟随巡江湖', 'string', '春联配对'],
                    ['evening', '春联大赛.爆风逐蛇巡乐斗', '剑圣驱马守江山', 'string', '春联配对'],
                    ['evening', '春联大赛.辞蛇年八方进宝', '迎马年四季发财', 'string', '春联配对'],
                    ['evening', '春联大赛.辞蛇岁乐斗兴旺', '迎马年江湖昌隆', 'string', '春联配对'],
                    ['evening', '春联大赛.鹅游乐斗随金蛇', '客聚江湖伴玉骢', 'string', '春联配对'],
                    ['evening', '春联大赛.发财鹅迎蛇送福', '福马鹅接马添欢', 'string', '春联配对'],
                    ['evening', '春联大赛.福马鹅驰追蛇影', '发财鹅跃逐马蹄', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇点兵召佣兵', '福马点将唤神将', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇启岁临乐斗', '福马迎春踏江川', 'string', '春联配对'],
                    ['evening', '春联大赛.金蛇启运乐斗兴', '福马开泰江湖旺', 'string', '春联配对'],
                    ['evening', '春联大赛.剑圣鹅随蛇辞旧', '精灵鹅伴马迎新', 'string', '春联配对'],
                    ['evening', '春联大赛.乐斗聚贤逢蛇岁', '江湖结义遇马年', 'string', '春联配对'],
                    ['evening', '春联大赛.龙脊承蛇安大陆', '蛇矛御马定江山', 'string', '春联配对'],
                    ['evening', '春联大赛.灵蛇绕陆贺新岁', '神驹游湖庆吉年', 'string', '春联配对'],
                    ['evening', '春联大赛.灵蛇佑侠平安岁', '骏马护将吉祥年', 'string', '春联配对'],
                    ['evening', '春联大赛.青蛇点墨书新景', '白马挥毫绘锦天', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇矛映雪辉乐斗', '神弓凝霜耀江天', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇盘乐斗开鸿运', '马跃江湖纳吉缘', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇辞旧岁歌乐斗', '马迎新春笑江天', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇辞旧岁器魂盛', '马迎新春战力强', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇绕神弓呈吉兆', '马依龙脊展宏图', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇舞核弹惊天地', '马驰龙脊震乐斗', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇舞江湖凝器魂', '马驰乐斗聚侠心', 'string', '春联配对'],
                    ['evening', '春联大赛.蛇游乐斗寻知己', '马走江湖结善缘', 'string', '春联配对'],
                    ['evening', '春联大赛.神将摆摊卖撸串', '佣兵唠嗑送神装', 'string', '春联配对'],
                    ['evening', '春联大赛.巳蛇纳福发财鹅', '午马迎祥福马鹅', 'string', '春联配对'],
                    ['evening', '春联大赛.巳蛇送吉满大陆', '午马迎禧遍乐斗', 'string', '春联配对'],
                    ['evening', '春联大赛.笑红尘歌蛇报喜', '福马鹅舞马呈安', 'string', '春联配对'],
                    ['evening', '春联大赛.笑红尘随蛇纳福', '发财鹅伴马迎祥', 'string', '春联配对'],
                    ['evening', '春联大赛.玉蛇衔福临洲岸', '金马驮祥踏浪澜', 'string', '春联配对'],
                    ['evening', '爱的同心结.QQ', 'NULL', 'null', '赠送同心结的QQ列表'],
                ];

                $configCount = 0;
                foreach ($defaultConfigs as $config) {
                    try {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO {$prefix}default_configs (config_group, config_key, config_value, value_type, description) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute($config);
                        $configCount++;
                    } catch (PDOException $e) {}
                }
                $success[] = "✅ 已导入 {$configCount} 条默认配置";

                // ============================================
                // 生成数据库配置文件
                // ============================================
                $config_content = "<?php\n";
                $config_content .= "define('DB_HOST', '{$db_config['host']}');\n";
                $config_content .= "define('DB_PORT', '{$db_config['port']}');\n";
                $config_content .= "define('DB_NAME', '{$db_config['name']}');\n";
                $config_content .= "define('DB_USER', '{$db_config['user']}');\n";
                $config_content .= "define('DB_PASS', '{$db_config['pass']}');\n";
                $config_content .= "define('DB_PREFIX', '{$db_config['prefix']}');\n";
                $config_content .= "define('DB_CHARSET', 'utf8mb4');\n";
                $config_content .= "?>";

                if (!is_dir(ROOT_PATH . 'config')) {
                    mkdir(ROOT_PATH . 'config', 0755, true);
                }
                file_put_contents(DB_CONFIG_FILE, $config_content);
                $success[] = "✅ 数据库配置文件已生成";

                file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . " - 安装完成\n");
                $success[] = "✅ 安装锁已创建";

                header('Location: login/index.html');
                exit;

            } catch (PDOException $e) {
                $errors[] = "数据库连接失败: " . $e->getMessage();
            } catch (Exception $e) {
                $errors[] = "安装错误: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Q宠大乐斗助手 - 安装程序</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, "PingFang SC", "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #e6f7ff, #e6fff9);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            max-width: 760px;
            width: 100%;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 35px 30px;
            box-shadow: 0 8px 32px rgba(0,20,30,0.12);
            border: 1px solid rgba(180, 215, 236, 0.3);
        }
        h1 { font-size: clamp(22px, 5vw, 28px); color: #1a3a4a; margin-bottom: 6px; }
        .subtitle { color: #5a7a8a; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #e0edf5; padding-bottom: 16px; }
        .step-indicator { display: flex; gap: 6px; margin-bottom: 24px; flex-wrap: wrap; }
        .step-dot { padding: 4px 14px; border-radius: 20px; font-size: 13px; font-weight: 500; background: #e0edf5; color: #5a7a8a; }
        .step-dot.active { background: #60c5d8; color: white; }
        .step-dot.done { background: #43b889; color: white; }
        .message-box { padding: 14px 18px; border-radius: 12px; margin: 10px 0 16px 0; font-size: 14px; line-height: 1.6; }
        .error-box { background: #ffebee; color: #b71c1c; border-left: 4px solid #e53935; }
        .success-box { background: #e8f5e9; color: #1e5a2a; border-left: 4px solid #43a047; }
        .warning-box { background: #fff8e1; color: #7a5a1a; border-left: 4px solid #f9a825; }
        .info-box { background: #e3f2fd; color: #0d47a1; border-left: 4px solid #1e88e5; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 500; color: #2d5a6a; margin-bottom: 4px; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px 14px; border: 1px solid #b4d7ec; border-radius: 10px; font-size: 15px; background: #f0f7fb; transition: all 0.2s; }
        .form-group input:focus { outline: none; border-color: #60c5d8; box-shadow: 0 0 0 3px rgba(96,197,216,0.2); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        ul { padding-left: 20px; margin: 6px 0; }
        li { margin: 2px 0; }
        .btn { display: inline-block; padding: 12px 28px; background: linear-gradient(135deg, #60c5d8, #42b8c9); color: white; border: none; border-radius: 14px; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; text-align: center; min-width: 120px; }
        .btn:active { transform: scale(0.96); opacity: 0.8; }
        .btn-secondary { background: #e0edf5; color: #2d5a6a; }
        .btn-success { background: linear-gradient(135deg, #60c59c, #43b889); }
        .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .btn-group .btn { flex: 1; min-width: 100px; }
        .footer-note { margin-top: 18px; font-size: 13px; color: #7a9aaa; text-align: center; }
        .setup-hint { background: #e3f2fd; padding: 16px; border-radius: 12px; margin: 16px 0; border-left: 4px solid #1e88e5; }
        .setup-hint strong { color: #0d47a1; }
        .env-status { font-size: 13px; padding: 3px 0; }
        .env-status .pass { color: #2e7d32; }
        .env-status .fail { color: #c62828; }
        .env-status .warn { color: #e65100; }
        .required { color: #e53935; font-weight: bold; }
        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .container { padding: 20px 16px; }
            .step-dot { font-size: 11px; padding: 3px 10px; }
            .btn { padding: 10px 18px; font-size: 14px; min-width: 80px; }
        }
        @media (prefers-color-scheme: dark) {
            body { background: linear-gradient(135deg, #1a2332, #1a2a2a); }
            .container { background: rgba(30, 40, 50, 0.95); border-color: #2a4a5a; }
            h1 { color: #7aafc9; }
            .subtitle { color: #6a8a9a; border-bottom-color: #2a3a4a; }
            .step-dot { background: #2a3a4a; color: #8aaabc; }
            .step-dot.active { background: #60c5d8; color: #1a2a2a; }
            .step-dot.done { background: #43b889; color: #1a2a2a; }
            .error-box { background: #2a1a1a; color: #ef9a9a; border-left-color: #e53935; }
            .success-box { background: #1a2a1a; color: #81c784; border-left-color: #43a047; }
            .warning-box { background: #2a2a1a; color: #ffd54f; border-left-color: #f9a825; }
            .info-box { background: #1a2a3a; color: #64b5f6; border-left-color: #1e88e5; }
            .btn-secondary { background: #2a3a4a; color: #8aaabc; }
            .form-group label { color: #8aaabc; }
            .form-group input { background: #1a2a3a; border-color: #2a4a5a; color: #d0e0e8; }
            .form-group input:focus { border-color: #60c5d8; }
            .footer-note { color: #5a7a8a; }
            .setup-hint { background: #1a2a3a; border-left-color: #42a5f5; }
            .setup-hint strong { color: #64b5f6; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🐧 Q宠大乐斗助手</h1>
    <div class="subtitle">安装向导</div>
    
    <div class="step-indicator">
        <?php for ($i = 1; $i <= 3; $i++): ?>
            <?php $class = 'step-dot'; if ($i == $step) $class .= ' active'; elseif ($i < $step) $class .= ' done'; ?>
            <span class="<?= $class ?>">步骤 <?= $i ?></span>
        <?php endfor; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="message-box error-box">
            <strong>❌ 错误：</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($warnings)): ?>
        <div class="message-box warning-box">
            <strong>⚠️ 提示：</strong>
            <ul><?php foreach ($warnings as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="message-box success-box">
            <strong>✅ 检测通过：</strong>
            <ul><?php foreach ($success as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <!-- 步骤1: 环境检测 -->
    <?php if ($step == 1): ?>
        <div class="message-box info-box">
            <strong>📋 环境检测</strong>
            <p style="margin-top:6px;">检测 PHP 扩展、进程控制函数、外部依赖及目录权限</p>
        </div>
        
        <div style="margin-top:10px;">
            <div class="env-status">
                PHP 版本: <?= PHP_VERSION ?>
                <?php if (version_compare(PHP_VERSION, '7.0.0', '>=')): ?>
                    <span class="pass">✅</span>
                <?php else: ?>
                    <span class="fail">❌</span>
                <?php endif; ?>
            </div>
            
            <?php
            $extensions = [
                'session' => 'Session',
                'pdo_mysql' => 'PDO MySQL',
                'json' => 'JSON',
                'pcre' => 'PCRE'
            ];
            foreach ($extensions as $ext => $name):
            ?>
                <div class="env-status">
                    <?= $name ?> (<?= $ext ?>):
                    <?php if (extension_loaded($ext)): ?>
                        <span class="pass">✅</span>
                    <?php else: ?>
                        <span class="fail">❌</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <div class="env-status" style="margin-top:6px;">
                <?php
                $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
                $disabled = array_filter($disabled);
                $proc_functions = ['proc_open', 'proc_close', 'proc_get_status', 'stream_select'];
                $blocked = array_intersect($proc_functions, $disabled);
                if (empty($blocked)):
                ?>
                    <span class="pass">✅ 进程控制函数可用</span>
                <?php else: ?>
                    <span class="fail">❌ 以下函数被禁用: <?= implode(', ', $blocked) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="env-status">
                Python3: 
                <?php 
                    $py = @shell_exec("/usr/bin/python3 --version 2>&1");
                    if ($py): 
                ?>
                    <span class="pass">✅ <?= trim($py) ?></span>
                <?php else: ?>
                    <span class="fail">❌ 未安装</span>
                <?php endif; ?>
            </div>
            <div class="env-status">
                Node.js: 
                <?php 
                    $node = @shell_exec("node --version 2>&1");
                    if ($node): 
                ?>
                    <span class="pass">✅ <?= trim($node) ?></span>
                <?php else: ?>
                    <span class="fail">❌ 未安装</span>
                <?php endif; ?>
            </div>
            <div class="env-status">
                uv:
                <?php 
                    $uv = @shell_exec("/usr/local/bin/uv --version 2>&1");
                    if ($uv): 
                ?>
                    <span class="pass">✅ <?= trim($uv) ?></span>
                <?php else: ?>
                    <span class="fail">❌ 未安装</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($errors)): ?>
            <div class="btn-group">
                <a href="?step=2" class="btn">下一步 → 配置数据库</a>
            </div>
        <?php else: ?>
            <div class="btn-group">
                <a href="?step=1" class="btn btn-secondary">🔄 重新检测</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 步骤2: 数据库配置 -->
    <?php if ($step == 2): ?>
        <div class="message-box info-box">
            <strong>📊 数据库配置</strong>
            <p style="margin-top:6px;font-size:13px;">请输入 MySQL 数据库连接信息</p>
        </div>

        <form method="post">
            <div class="form-row">
                <div class="form-group">
                    <label>数据库主机 <span class="required">*</span></label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>端口 <span class="required">*</span></label>
                    <input type="text" name="db_port" value="3306" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>数据库名 <span class="required">*</span></label>
                    <input type="text" name="db_name" placeholder="请输入数据库名称" required>
                </div>
                <div class="form-group">
                    <label>表前缀</label>
                    <input type="text" name="db_prefix" value="qpet_">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>数据库用户名 <span class="required">*</span></label>
                    <input type="text" name="db_user" placeholder="请输入数据库用户名" required>
                </div>
                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="db_pass" placeholder="请输入数据库密码">
                </div>
            </div>

            <div class="setup-hint">
                <strong>📌 安装说明：</strong>
                <ol style="margin-top:6px;padding-left:18px;line-height:1.8;">
                    <li>安装程序将自动创建 8 个数据表</li>
                    <li>从 <code>noon.py</code> 和 <code>evening.py</code> 动态提取任务列表</li>
                    <li>导入 100+ 条默认配置（作为全局模板）</li>
                    <li>每个QQ子账号将拥有独立的配置和快捷任务表（<code>account_configs</code>、<code>account_quick_tasks</code>）</li>
                    <li>安装完成后自动跳转到扫码登录页面</li>
                </ol>
            </div>

            <div class="btn-group">
                <button type="submit" name="install_db" class="btn btn-success">🚀 开始安装</button>
                <a href="?step=1" class="btn btn-secondary">← 返回检测</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>