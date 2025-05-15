<?php
/**
 * Metrics Configuration
 *
 * This file contains configuration settings for the metrics collection and reporting system.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Default configuration
$config = [
    // Enable or disable metrics collection
    'enabled' => true,
    
    // Metrics endpoint settings
    'endpoint' => [
        'path' => '/metrics',
        'enabled' => true,
        'auth' => [
            'enabled' => true,
            'username' => 'prometheus',
            'password' => 'willhaben_metrics', // This should be changed in production
            'ip_whitelist' => [
                '127.0.0.1',         // Localhost
                '::1',               // IPv6 localhost
                '10.0.0.0/8',        // Internal network
                '172.16.0.0/12',     // Internal network
                '192.168.0.0/16'     // Internal network
            ]
        ],
        'cache_ttl' => 10, // Seconds to cache the metrics response
        'timeout' => 5,    // Seconds before timeout for metrics generation
    ],
    
    // Metrics retention settings
    'retention' => [
        'counters' => 86400,     // 1 day for counters
        'gauges' => 3600,        // 1 hour for gauges
        'histograms' => 43200,   // 12 hours for histograms
    ],
    
    // Aggregation settings
    'aggregation' => [
        'interval' => 60,        // Aggregate metrics every 60 seconds
        'enabled' => true,       // Enable metrics aggregation
        'storage_limit' => 1000, // Maximum number of series to store
    ],
    
    // Sampling settings (to reduce overhead)
    'sampling' => [
        'rate' => 1.0,           // 100% of requests are sampled
        'static_files' => 0.1,   // Sample only 10% of static file metrics
    ],
    
    // Environment-specific overrides
    'environments' => [
        'development' => [
            'endpoint' => [
                'auth' => [
                    'enabled' => false
                ]
            ],
            'sampling' => [
                'rate' => 1.0
            ]
        ],
        'testing' => [
            'enabled' => false,
        ],
        'staging' => [
            'endpoint' => [
                'auth' => [
                    'ip_whitelist' => [
                        '127.0.0.1',
                        '::1',
                    ]
                ]
            ]
        ],
        'production' => [
            // Production uses base config
        ]
    ],
    
    // Metrics to collect
    'collect' => [
        'request_duration' => true,
        'response_size' => true,
        'memory_usage' => true,
        'cache_operations' => true,
        'redirects' => true,
        'errors' => true,
        'rate_limits' => true,
        'static_files' => true,
    ]
];

// Apply environment-specific overrides
if (isset($config['environments'][$environment])) {
    $config = array_replace_recursive($config, $config['environments'][$environment]);
}

// Remove environments from final config
unset($config['environments']);

return $config;

