<?php
/**
 * Rate Limiting Configuration
 *
 * This file contains configuration settings for the rate limiter.
 * Different values can be set for different environments.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Default configuration
$config = [
    // Enable or disable rate limiting
    'enabled' => true,
    
    // Exponential backoff configuration for abusive clients
    'backoff' => [
        'enabled' => true,
        'attempts' => [5, 10, 25, 50, 100],   // Attempt thresholds
        'multipliers' => [1, 2, 4, 8, 16],    // Multipliers for each threshold
        'window' => 3600,                     // Time window for attempts (seconds)
        'max_block_time' => 86400,            // Maximum block time (24 hours)
        'decay_rate' => 0.5,                  // Rate at which penalties decay
    ],
    
    // Trusted client bypass tokens
    'bypass_tokens' => [
        'enabled' => true,
        'tokens' => [
            // 'a1b2c3d4e5f6g7h8' => ['name' => 'Internal API', 'unlimited' => false, 'quota' => 1000],
            // Add real tokens in production with appropriate quotas
        ],
        'header_name' => 'X-Rate-Limit-Token', // HTTP header for token authentication
        'parameter_name' => 'rate_token',      // Query parameter alternative
        'log_usage' => true,                   // Log token usage for auditing
    ],
    
    // User-based rate limiting (in addition to IP-based)
    'user_limits' => [
        'enabled' => true,
        'identifier' => 'session_id',  // How to identify users (session_id, user_id, etc.)
        'cookie_name' => 'PHPSESSID',  // Cookie to check for session identifier
        'header_name' => 'X-Session-ID', // Alternative header for identification
        'fallback_to_ip' => true,      // Use IP if user identifier not available
        
        // User roles and their rate limits
        'roles' => [
            'anonymous' => ['factor' => 1.0],     // Default multiplier
            'registered' => ['factor' => 2.0],    // 2x the base limit
            'premium' => ['factor' => 5.0],       // 5x the base limit
            'admin' => ['factor' => 10.0],        // 10x the base limit
        ],
    ],
    
    // Cluster configuration for distributed rate limiting
    'cluster' => [
        'enabled' => false,
        'sync_method' => 'redis',      // Method for synchronizing rate limits across nodes
        'sync_key_prefix' => 'rate_limit_sync:',
        'sync_interval' => 5,          // Seconds between synchronization
        'node_id' => null,             // Auto-generated if null, or specify explicitly
        'reset_on_deploy' => false,    // Whether to reset limits on deployment
    ],
    
    // Redis connection settings
    'redis' => [
        'development' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'key_prefix' => 'rate_limit:dev:',
            'connect_timeout' => 1,
            'enabled' => true
        ],
        'testing' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1,
            'key_prefix' => 'rate_limit:test:',
            'connect_timeout' => 1,
            'enabled' => false  // Disabled in testing environment
        ],
        'staging' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 2,
            'key_prefix' => 'rate_limit:staging:',
            'connect_timeout' => 2,
            'enabled' => true
        ],
        'production' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 3,
            'key_prefix' => 'rate_limit:prod:',
            'connect_timeout' => 2,
            'enabled' => true,
            // Use a socket instead of TCP connection for better performance
            // 'socket' => '/var/run/redis/redis.sock',
            // Uncomment and set auth if Redis requires authentication
            // 'auth' => 'password',
        ]
    ],
    
    // Rate limits for specific routes
    'routes' => [
        // Seller profile redirects - higher limits
        'seller_profile' => [
            'requests' => 60,   // 60 requests
            'period' => 60      // per minute
        ],
        
        // Product redirects - higher limits
        'product' => [
            'requests' => 120,  // 120 requests
            'period' => 60      // per minute
        ],
        
        // Admin routes - stricter limits
        'admin' => [
            'requests' => 30,   // 30 requests
            'period' => 60      // per minute
        ],
        
        // API routes - very strict limits
        'api' => [
            'requests' => 20,   // 20 requests
            'period' => 60      // per minute
        ],
        
        // Static file requests - higher limits
        'static' => [
            'requests' => 300,  // 300 requests
            'period' => 60      // per minute
        ]
    ],
    
    // Pattern-based rate limits
    'patterns' => [
        '#^/admin/#' => [
            'requests' => 30,
            'period' => 60
        ],
        '#^/api/#' => [
            'requests' => 20,
            'period' => 60
        ]
    ],
    
    // Default rate limit for routes not specifically configured
    'default' => [
        'requests' => 60,      // 60 requests
        'period' => 60         // per minute
    ]
];

// Return the configuration for the current environment
return $config;
