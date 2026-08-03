"""
配置加载模块，提供从数据库读取配置的统一接口
"""

from typing import Optional, Any, Final
import json
import pymysql
from src.tasks.register import TaskModule
from src.exceptions import ConfigKeyError, ConfigError


class ConfigResolver:
    """配置解析器，支持三层回退：用户配置 → 默认配置 → 异常"""

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
        
        self._account = self._load_account_config()
        self._default = self._load_default_config()

        self._account_config = self._account.get(self._module.value if hasattr(self._module, 'value') else str(self._module))
        self._default_config = self._default.get(self._module.value if hasattr(self._module, 'value') else str(self._module))

    def get(self, key: str) -> Any:
        """获取配置值，直接用完整 key 查找"""
        # 第一层：用户配置
        if isinstance(self._account_config, dict):
            if key in self._account_config:
                return self._account_config[key]

        # 第二层：默认配置
        if isinstance(self._default_config, dict):
            if key in self._default_config:
                return self._default_config[key]

        raise ConfigKeyError(f"配置键 '{key}' 未找到")

    def _load_account_config(self) -> dict:
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
                
                result[group][key] = self._decode_value(value, value_type)
            
            return result
        except Exception as e:
            if "doesn't exist" in str(e):
                return {}
            print(f"⚠️ 加载用户配置失败: {e}")
            return {}

    def _load_default_config(self) -> dict:
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
            if "doesn't exist" in str(e):
                return {}
            print(f"⚠️ 加载默认配置失败: {e}")
            return {}

    def _decode_value(self, value: str, value_type: str) -> Any:
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
        return cls._db_conn

    @classmethod
    def load_cookies(cls, qq: Optional[str] = None, user_id: Optional[int] = None) -> dict[str, dict[str, str]]:
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


def get_config(qq: str, module: TaskModule, user_id: Optional[int] = None) -> ConfigResolver:
    db = Config.get_db()
    return ConfigResolver(qq, module, db, user_id)


def get_cookie(qq: str, user_id: Optional[int] = None) -> Optional[dict]:
    cookies = Config.load_cookies(qq, user_id)
    return cookies.get(qq) if cookies else None
