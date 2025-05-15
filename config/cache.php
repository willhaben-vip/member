<?php
/**
 * Cache Configuration
 *
 * This file contains configuration settings for the caching system.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Default configuration
$config = [
    // Enable or disable caching
    'enabled' => true,
    
    // Default cache TTL in seconds
    'ttl' => [
        'default' => 3600,        // 1 hour
        'seller' => 86400,        // 1 day
        'product' => 43200,       // 12 hours
        'static' => 604800,       // 1 week
        'temp' => 300             // 5 minutes
    ],
    
    // Key prefixing system for better organization
    'key_prefix' => [
        'seller' => 'seller:',
        'product' => 'product:',
        'static' => 'static:',
        'metrics' => 'metrics:',
        'rate_limit' => 'rate_limit:',
        'system' => 'system:'
    ],
    
    // Cache warm-up configuration
    'warm_up' => [
        'enabled' => true,
        // Sellers to pre-load on application start
        'preload_sellers' => [
            '34434899' // rene.kapusta
        ],
        // Percentage of sellers to cache on warm-up (0-100)
        'seller_cache_percent' => 20,
        // Maximum number of items to warm up
        'max_items' => 1000,
        // Randomize warm-up order to prevent hotspots
        'randomize' => true
    ],
    
    // Redis connection settings
    'redis' => [
        'development' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'cache:dev:',
            'connect_timeout' => 1,
            'enabled' => true
        ],
        'testing' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1,
            'prefix' => 'cache:test:',
            'connect_timeout' => 1,
            'enabled' => false  // Disabled in testing environment
        ],
        'staging' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 2,
            'prefix' => 'cache:staging:',
            'connect_timeout' => 2,
            'enabled' => true
        ],
        'production' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 3,
            'prefix' => 'cache:prod:',
            'connect_timeout' => 2,
            'enabled' => true,
            // Use a socket instead of TCP connection for better performance in production
            // 'socket' => '/var/run/redis/redis.sock',
            // Uncomment and set auth if Redis requires authentication
            // 'auth' => 'password',
            // Connection pooling settings
            'persistent_id' => 'willhaben_prod',  // Enable persistent connections
            'pool_size' => 5,                     // Number of connections in the pool
            'idle_timeout' => 60,                 // Seconds before idle connections are closed
            'retry_interval' => 100,              // Milliseconds between connection retries
            'read_timeout' => 1.0,                // Read timeout in seconds
        ]
    ],
    
    // Redis cluster configuration for high availability
    'redis_cluster' => [
        'enabled' => false,  // Set to true to enable Redis Cluster mode
        'nodes' => [
            // List of cluster nodes in host:port format
            '127.0.0.1:7000',
            '127.0.0.1:7001',
            '127.0.0.1:7002',
            // Add more nodes as needed
        ],
        'options' => [
            'cluster' => 'redis',                 // Use Redis native clustering
            'prefix' => 'cache:cluster:',         // Key prefix for cluster mode
            'read_timeout' => 1.0,                // Read timeout
            'persistent' => true,                 // Use persistent connections
            'auth' => [                           // Cluster authentication if needed
                'user' => null,                   // For Redis 6+ ACL
                'pass' => null                    // Password
            ],
            'failover' => 'error',                // Options: none, error, distribute
            'readonly_mode' => false              // Whether to connect in readonly mode
        ]
    ],
    
    // File cache settings
    'file' => [
        'path' => [
            'development' => __DIR__ . '/../var/cache/dev',
            'testing' => __DIR__ . '/../var/cache/test',
            'staging' => __DIR__ . '/../var/cache/staging',
            'production' => '/var/cache/willhaben'
        ]
    ],
    
    // Cache invalidation settings
    'invalidation' => [
        'probability' => 0.01,    // 1% chance of garbage collection
        'max_lifetime' => 2592000 // 30 days maximum lifetime for any cache entry
    ]
];

// Get environment-specific settings
$redisConfig = $config['redis'][$environment] ?? $config['redis']['production'];
$fileCachePath = $config['file']['path'][$environment] ?? $config['file']['path']['production'];

// Set environment-specific cache settings
$config['redis_config'] = $redisConfig;
$config['file_cache_path'] = $fileCachePath;

return $config;

