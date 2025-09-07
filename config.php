<?php
/**
 * 配置文件 - 所有可配置选项集中在此
 */

// 接口列表文件
define('API_LIST_FILE', 'json.txt'); // 接口列表文件路径

// 缓存配置
define('CACHE_PATH', 'cache/'); // 缓存文件夹
define('CACHE_EXPIRE', 3600); // 缓存失效时间，单位：秒（默认1小时）
define('CACHE_ENABLED', true); // 缓存开关，true 为开启，false 为关闭

// 下载功能
define('DOWNLOAD_ENABLED', false); // true 启用下载，false 禁用下载（默认）

// API源显示
define('SHOW_API_SOURCE', false); // true 显示API源，false 不显示

// IP授权功能
define('IP_AUTH_ENABLED', false); // true 开启 IP 授权，false 关闭
define('IP_AUTH_FILE', 'ipsq.txt'); // IP授权列表文件路径

// 调试模式
define('DEBUG_MODE', false); // true 开启调试模式，显示更多错误信息

// 是否在msg中显示URL
define('SHOW_URL_IN_MSG', true); // true 在msg中显示URL，false 不显示

// 并发请求设置
define('CONCURRENT_REQUESTS', true); // 启用并发API请求
define('REQUEST_TIMEOUT', 5); // 单个API请求超时时间(秒)

// DMCA规避设置
define('BYPASS_DMCA', true); // 启用DMCA规避策略
define('USE_PROXY', false); // 是否使用代理服务器
define('PROXY_LIST', 'proxy.txt'); // 代理服务器列表文件
define('RANDOM_USER_AGENT', true); // 随机User-Agent
define('REFERER_STRATEGY', true); // 使用Referer策略

// 密钥验证配置
define('LICENSE_ENABLED', true); // 是否开启密钥验证
define('LICENSE_FILE', 'license.key'); // 密钥文件路径
define('LICENSE_KEY', 'your-license-key-here'); // 配置中的密钥，需要与license.key文件中的密钥一致

// 日志配置
define('LOG_ENABLED', true); // 是否启用日志记录
define('LOG_LEVEL', DEBUG_MODE ? 'DEBUG' : 'INFO'); // 日志级别

// 健康检查配置
define('HEALTH_CHECK_ENABLED', true); // 是否启用API健康检查
define('HEALTH_CHECK_INTERVAL', 3600); // 健康检查间隔，单位：秒（默认1小时）

// 限流配置
define('RATE_LIMIT_ENABLED', true); // 是否启用限流
define('MAX_REQUESTS_PER_MINUTE', 60); // 每分钟最大请求数
define('MAX_REQUESTS_PER_HOUR', 1000); // 每小时最大请求数
define('MAX_REQUESTS_PER_DAY', 5000); // 每天最大请求数
define('BAN_DURATION', 3600); // 违规封禁时长(秒)