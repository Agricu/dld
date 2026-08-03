"""
自定义异常类
"""

class ConfigError(Exception):
    """配置模块基础异常"""
    pass


class ConfigFileNotFoundError(ConfigError):
    """配置文件不存在"""
    pass


class ConfigKeyError(ConfigError):
    """配置键错误"""
    pass


class ConfigYAMLError(ConfigError):
    """配置解析错误"""
    pass
