#!/usr/bin/env python3
"""
Q宠大乐斗任务执行入口
"""

import sys
import re
import asyncio
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from src.config_loader import Config
from src.tasks.register import TaskModule, get_module_tasks
from src.run import TaskRunner


def load_db_config():
    """从 database.inc.php 读取数据库配置"""
    config_file = Path(__file__).parent / 'config' / 'database.inc.php'
    
    if not config_file.exists():
        print(f"❌ 数据库配置文件不存在: {config_file}")
        print("请先运行 install.php 完成安装")
        sys.exit(1)
    
    try:
        content = config_file.read_text(encoding='utf-8')
        
        config = {}
        for match in re.finditer(r"define\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)", content):
            config[match.group(1)] = match.group(2)
        
        required = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME']
        missing = [k for k in required if k not in config or not config[k]]
        if missing:
            print(f"❌ 数据库配置缺失: {', '.join(missing)}")
            sys.exit(1)
        
        return {
            'host': config.get('DB_HOST', 'localhost'),
            'port': int(config.get('DB_PORT', 3306)),
            'user': config.get('DB_USER', ''),
            'password': config.get('DB_PASS', ''),
            'database': config.get('DB_NAME', ''),
            'prefix': config.get('DB_PREFIX', 'qpet_'),
            'charset': config.get('DB_CHARSET', 'utf8mb4')
        }
        
    except Exception as e:
        print(f"❌ 读取配置文件失败: {e}")
        sys.exit(1)


def resolve_username(db, username):
    """解析用户名：如果是主账户直接返回，如果是子账号返回所属主账户"""
    # 1. 检查是否为主账户
    cursor = db.cursor()
    cursor.execute("SELECT id FROM qpet_users WHERE username = %s", [username])
    user = cursor.fetchone()
    cursor.close()
    
    if user:
        return username  # 是主账户
    
    # 2. 检查是否为子账号
    cursor = db.cursor()
    cursor.execute("SELECT user_id FROM qpet_accounts WHERE newuin = %s AND enabled = 1", [username])
    account = cursor.fetchone()
    cursor.close()
    
    if account:
        # 获取所属主账户的用户名
        cursor = db.cursor()
        cursor.execute("SELECT username FROM qpet_users WHERE id = %s", [account[0]])
        main_user = cursor.fetchone()
        cursor.close()
        if main_user:
            return main_user[0]
    
    return None


def main():
    # 1. 从 database.inc.php 读取数据库配置
    db_config = load_db_config()
    
    Config.init_db(
        host=db_config['host'],
        port=db_config['port'],
        user=db_config['user'],
        password=db_config['password'],
        database=db_config['database'],
        prefix=db_config['prefix']
    )
    
    # 2. 解析参数
    args = sys.argv[1:]
    if len(args) == 0:
        print("用法: uv run main.py <QQ号.时段.任务名>")
        print("示例: uv run main.py 1004571093.noon.好友")
        print("      uv run main.py 643600871.evening.江湖长梦")
        print("时段: noon 或 evening")
        sys.exit(1)
    
    arg = args[0]
    parts = arg.split('.')
    
    if len(parts) < 2:
        print(f"❌ 参数格式错误: {arg}")
        print("格式: QQ号.时段.任务名")
        sys.exit(1)
    
    input_qq = parts[0]      # 用户输入的 QQ 号（可能是主账户或子账号）
    time_slot = parts[1]     # noon 或 evening
    task_name = parts[2] if len(parts) > 2 else None
    
    # 3. 解析用户：如果是子账号，找到所属主账户
    db = Config.get_db()
    main_username = resolve_username(db, input_qq)
    
    if not main_username:
        print(f"❌ 用户 {input_qq} 不存在（既不是主账户也不是子账号）")
        sys.exit(1)
    
    # 4. 获取主账户的 user_id
    cursor = db.cursor()
    cursor.execute("SELECT id FROM qpet_users WHERE username = %s", [main_username])
    user = cursor.fetchone()
    cursor.close()
    
    if not user:
        print(f"❌ 主账户 {main_username} 不存在")
        sys.exit(1)
    
    user_id = user[0]
    
    # 5. 加载该用户的所有 Cookie
    cookies = Config.load_cookies(user_id=user_id)
    
    if not cookies:
        print("❌ 未获取到任何 Cookie")
        sys.exit(1)
    
    # 6. 检查输入的 QQ 是否在 Cookie 中
    if input_qq not in cookies:
        print(f"❌ 账号 {input_qq} 的 Cookie 不存在")
        print(f"   可用账号: {', '.join(cookies.keys())}")
        sys.exit(1)
    
    # 7. 只使用指定的账号
    filtered_cookies = {input_qq: cookies[input_qq]}
    
    # 8. 获取任务模块
    module = TaskModule.noon if time_slot == 'noon' else TaskModule.evening
    registry = get_module_tasks(module)
    
    if task_name:
        if task_name not in registry:
            print(f"❌ 任务 '{task_name}' 不存在")
            print(f"   可用任务: {', '.join(list(registry.keys())[:10])}...")
            sys.exit(1)
        registry = {task_name: registry[task_name]}
    print(f"✅ 开始执行...")
    
    asyncio.run(TaskRunner(filtered_cookies, module, registry).run())


if __name__ == '__main__':
    main()
