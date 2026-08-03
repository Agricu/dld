"""
配置加载模块，提供从数据库读取配置的统一接口
"""

from typing import Optional, Any, Final
import json
import pymysql
from src.tasks.register import TaskModule


class ConfigError(Exception):
    """配置模块基础异常"""
    pass


class ConfigFileNotFoundError(ConfigError):
    """配置不存在"""
    pass


class ConfigKeyError(ConfigError):
    """配置键错误"""
    pass


class ConfigYAMLError(ConfigError):
    """配置解析错误"""
    pass


class ConfigResolver:
    """配置解析器，支持三层回退：用户配置 → default.yaml → 异常"""

    NOT_FOUND: Final = object()

    def __init__(
        self,
        qq: str,
        module: TaskModule,
        db_conn: Any = None,
        user_id: Optional[int] = None,
    ):
        self._qq = qq
        self._module = module
        self._db = db_conn
        self._user_id = user_id
        self._prefix = "qpet_"
        
        # 加载配置
        self._account = self._load_account_config()
        self._default = self._load_default_config()

        if (
            self._module not in self._account
            and self._module not in self._default
        ):
            raise ConfigKeyError(
                f"配置键 {self._module} 不存在，"
                f"你扩展了 {self._module} 模块？ -> "
                f"在账号配置或者默认配置添加 '{self._module}: null'"
            )

        self._account_config = self._account.get(self._module)
        self._default_config = self._default.get(self._module)

    def get(self, key: str) -> Any:
        """获取配置值

        优先级：
        1. 用户配置中存在该键（包括 null）→ 使用
        2. 默认配置中存在该键 → 使用
        3. 都不存在 → 抛出 ConfigKeyError
        """
        keys = key.split(".")

        # 第一层：用户配置
        if isinstance(self._account_config, dict):
            account_value = self._deep_get(self._account_config, keys)
            if account_value is not self.NOT_FOUND:
                return account_value

        # 第二层：默认配置
        if isinstance(self._default_config, dict):
            default_value = self._deep_get(self._default_config, keys)
            if default_value is not self.NOT_FOUND:
                return default_value

        raise ConfigKeyError(f"配置键 '{self._module}.{key}' 未找到")

    def _deep_get(self, data: dict, keys: list[str]) -> Any:
        """深层字典取值"""
        current = data
        for k in keys:
            if not isinstance(current, dict) or k not in current:
                return self.NOT_FOUND
            current = current[k]
        return current

    def _load_account_config(self) -> dict:
        """从数据库加载用户配置"""
        if not self._db or not self._user_id:
            return {}
        
        try:
            cursor = self._db.cursor()
            sql = f"""
                SELECT config_group, config_key, config_value, value_type
                FROM {self._prefix}user_configs
                WHERE user_id = %s
            """
            cursor.execute(sql, [self._user_id])
            rows = cursor.fetchall()
            cursor.close()
            
            result = {}
            for row in rows:
                group = row[0]
                key = row[1]
                value = row[2]
                value_type = row[3]
                
                if group not in result:
                    result[group] = {}
                
                # 解析值
                result[group][key] = self._decode_value(value, value_type)
            
            return result
        except Exception as e:
            print(f"⚠️ 加载用户配置失败: {e}")
            return {}

    def _load_default_config(self) -> dict:
        """从数据库加载默认配置"""
        if not self._db:
            return {}
        
        try:
            cursor = self._db.cursor()
            sql = f"""
                SELECT config_group, config_key, config_value, value_type
                FROM {self._prefix}default_configs
            """
            cursor.execute(sql)
            rows = cursor.fetchall()
            cursor.close()
            
            result = {}
            for row in rows:
                group = row[0]
                key = row[1]
                value = row[2]
                value_type = row[3]
                
                if group not in result:
                    result[group] = {}
                
                result[group][key] = self._decode_value(value, value_type)
            
            return result
        except Exception as e:
            print(f"⚠️ 加载默认配置失败: {e}")
            return {}

    def _decode_value(self, value: str, value_type: str) -> Any:
        """解码配置值"""
        if value is None or value == 'NULL':
            return None
        
        try:
            if value_type == 'int':
                return int(value)
            elif value_type == 'bool':
                return value.lower() in ('true', '1', 'yes')
            elif value_type in ('array', 'object'):
                return json.loads(value)
            else:
                return value
        except (json.JSONDecodeError, ValueError):
            return value


class Config:
    
    _db_conn = None
    _prefix = "qpet_"
    
    @classmethod
    def init_db(cls, host: str, port: int, user: str, password: str, database: str, prefix: str = "qpet_"):
        """初始化数据库连接"""
        cls._prefix = prefix
        cls._db_conn = pymysql.connect(
            host=host,
            port=port,
            user=user,
            password=password,
            database=database,
            charset='utf8mb4',
            autocommit=True
        )
        return cls._db_conn

    @classmethod
    def get_db(cls):
        """获取数据库连接"""
        return cls._db_conn

    @classmethod
    def _load_from_db(cls, table: str, condition: str = "", params: list = None) -> dict:
        """从数据库加载配置"""
        if not cls._db_conn:
            return {}
        
        try:
            cursor = cls._db_conn.cursor()
            sql = f"""
                SELECT config_group, config_key, config_value, value_type
                FROM {cls._prefix}{table}
            """
            if condition:
                sql += f" WHERE {condition}"
            
            if params:
                cursor.execute(sql, params)
            else:
                cursor.execute(sql)
            
            rows = cursor.fetchall()
            cursor.close()
            
            result = {}
            for row in rows:
                group = row[0]
                key = row[1]
                value = row[2]
                value_type = row[3]
                
                if group not in result:
                    result[group] = {}
                
                result[group][key] = cls._decode_value(value, value_type)
            
            return result
        except Exception as e:
            print(f"⚠️ 加载配置失败: {e}")
            return {}

    @classmethod
    def _decode_value(cls, value: str, value_type: str) -> Any:
        """解码配置值"""
        if value is None or value == 'NULL':
            return None
        
        try:
            if value_type == 'int':
                return int(value)
            elif value_type == 'bool':
                return value.lower() in ('true', '1', 'yes')
            elif value_type in ('array', 'object'):
                return json.loads(value)
            else:
                return value
        except (json.JSONDecodeError, ValueError):
            return value

    @classmethod
    def load_user_config(cls, user_id: int) -> dict:
        """加载用户配置"""
        return cls._load_from_db("user_configs", "user_id = %s", [user_id])

    @classmethod
    def load_default_config(cls) -> dict:
        """加载默认配置"""
        return cls._load_from_db("default_configs")

    @classmethod
    def load_cookies(cls, qq: Optional[str] = None, user_id: Optional[int] = None) -> dict[str, dict[str, str]]:
        """
        从数据库加载大乐斗 Cookie，支持按 QQ 号过滤

        Args:
            qq: 需要获取 Cookie 的 QQ 号
            user_id: 用户ID

        Returns:
            字典格式：{qq: {"newuin": qq, "openId": xxx, "accessToken": xxx}, ...}
        """
        if not cls._db_conn:
            return {}
        
        result = {}
        try:
            cursor = cls._db_conn.cursor()
            
            if user_id:
                sql = f"""
                    SELECT open_id, newuin, access_token, nickname
                    FROM {cls._prefix}accounts
                    WHERE user_id = %s AND enabled = 1
                """
                params = [user_id]
            else:
                sql = f"""
                    SELECT open_id, newuin, access_token, nickname
                    FROM {cls._prefix}accounts
                    WHERE enabled = 1
                """
                params = []
            
            cursor.execute(sql, params)
            rows = cursor.fetchall()
            cursor.close()
            
            for row in rows:
                open_id = row[0]
                newuin = row[1]
                access_token = row[2]
                nickname = row[3]
                
                if not newuin:
                    continue
                
                cookie_dict = {
                    "newuin": newuin,
                    "openId": open_id,
                    "accessToken": access_token,
                    "nickname": nickname or newuin
                }
                
                if qq is not None and qq == newuin:
                    return {qq: cookie_dict}
                
                result[newuin] = cookie_dict
            
            return {} if qq is not None else result
            
        except Exception as e:
            print(f"⚠️ 加载 Cookie 失败: {e}")
            return {}


# ========== 便捷函数 ==========

def get_config(qq: str, module: TaskModule, user_id: Optional[int] = None) -> ConfigResolver:
    """
    获取配置解析器
    
    Args:
        qq: QQ号
        module: 任务模块
        user_id: 用户ID（可选）
    
    Returns:
        ConfigResolver 实例
    """
    db = Config.get_db()
    return ConfigResolver(qq, module, db, user_id)


def get_cookie(qq: str, user_id: Optional[int] = None) -> Optional[dict]:
    """
    获取指定 QQ 的 Cookie
    
    Args:
        qq: QQ号
        user_id: 用户ID
    
    Returns:
        Cookie 字典，未找到返回 None
    """
    cookies = Config.load_cookies(qq, user_id)
    return cookies.get(qq) if cookies else None