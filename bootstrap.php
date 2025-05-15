<?php
/**
 * Application Bootstrap
 *
 * This file initializes the application, loads configuration,
 * and sets up core components.
 */

// Define application root path
define('APP_ROOT', __DIR__);

// Load configurations
$loggingConfig = require_once __DIR__ . '/config/logging.php';
$rateLimitingConfig = require_once __DIR__ . '/config/rate_limiting.php';
$validationConfig = require_once __DIR__ . '/config/validation.php';
$cacheConfig = require_once __DIR__ . '/config/cache.php';
$securityConfig = require_once __DIR__ . '/config/security.php';
$staticAssetsConfig = require_once __DIR__ . '/config/static_assets.php';

// Setup autoloading (simple version without Composer)
spl_autoload_register(function ($class) {
    // Convert namespace separators to directory separators
    $class = str_replace('\\', '/', $class);
    
    // Base directory for class files
    $baseDir = __DIR__ . '/src/';
    
    // Attempt to load the class
    $file = $baseDir . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    
    return false;
});

// Initialize logger
$logger = new Willhaben\Logger\JsonLogger(LOG_PATH, LOG_LEVEL);

// Initialize log rotator
$logRotator = new Willhaben\Logger\LogRotator(LOG_PATH, LOG_ROTATION_MAX_SIZE, LOG_ROTATION_MAX_FILES);
$logRotator->checkAndRotate();

// Make logger available globally (not ideal but compatible with existing code)
$GLOBALS['logger'] = $logger;

// Get environment-specific Redis configuration
$environment = getenv('APP_ENV') ?: 'production';
$redisConfig = $rateLimitingConfig['redis'][$environment] ?? $rateLimitingConfig['redis']['production'];

// Initialize rate limiter
$rateLimiter = new Willhaben\Security\RateLimiter([
    'enabled' => $redisConfig['enabled'] ?? true,
    'host' => $redisConfig['host'] ?? '127.0.0.1',
    'port' => $redisConfig['port'] ?? 6379,
    'database' => $redisConfig['database'] ?? 0,
    'socket' => $redisConfig['socket'] ?? null,
    'auth' => $redisConfig['auth'] ?? null,
    'key_prefix' => $redisConfig['key_prefix'] ?? 'rate_limit:',
    'connect_timeout' => $redisConfig['connect_timeout'] ?? 2,
    'routes' => $rateLimitingConfig['routes'] ?? [],
    'patterns' => $rateLimitingConfig['patterns'] ?? [],
    'default' => $rateLimitingConfig['default'] ?? [
        'requests' => 60,
        'period' => 60
    ]
], $logger);

// Make rate limiter available globally
$GLOBALS['rateLimiter'] = $rateLimiter;

// Initialize request validator
$requestValidator = new Willhaben\Security\RequestValidator($validationConfig, $logger);

// Make request validator available globally
$GLOBALS['requestValidator'] = $requestValidator;

// Initialize cache system
// 1. Create Redis cache (primary)
$redisCache = new Willhaben\Cache\RedisCache(
    $cacheConfig['redis_config'],
    $logger,
    $cacheConfig['ttl']['default']
);

// 2. Create File cache (fallback)
$fileCache = new Willhaben\Cache\FileCache(
    $cacheConfig['file_cache_path'],
    $logger,
    $cacheConfig['ttl']['default']
);

// 3. Create Cache Manager (handles cache strategy)
$cacheManager = new Willhaben\Cache\CacheManager(
    $redisCache,
    $fileCache,
    $logger,
    $cacheConfig['ttl']['default']
);

// Make cache manager available globally
$GLOBALS['cacheManager'] = $cacheManager;

// Initialize security headers middleware
$securityHeadersMiddleware = new Willhaben\Middleware\SecurityHeadersMiddleware(
    $securityConfig,
    $logger
);

// Make security headers middleware available globally
$GLOBALS['securityHeadersMiddleware'] = $securityHeadersMiddleware;

// Application constants
define('BASE_URL', 'https://willhaben.vip');
define('SELLER_MAP', [
    '34434899' => 'rene.kapusta'
]);

return [
    'logger' => $logger,
    'logRotator' => $logRotator,
    'rateLimiter' => $rateLimiter,
    'requestValidator' => $requestValidator,
    'cacheManager' => $cacheManager,
    'securityHeadersMiddleware' => $securityHeadersMiddleware,
    'securityConfig' => $securityConfig,
    'staticAssetsConfig' => $staticAssetsConfig
];

