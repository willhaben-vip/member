<?php
/**
 * Request Validation Configuration
 *
 * This file contains configuration settings for request validation.
 * Different values can be set for different environments.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Default configuration
$config = [
    // Maximum header length (in characters)
    'max_header_length' => 8192, // 8KB

    // Maximum file size for uploads (in bytes)
    'max_file_size' => 10485760, // 10MB

    // Maximum request body size (in bytes)
    'max_request_size' => 20971520, // 20MB

    // Maximum number of query parameters
    'max_query_params' => 100,

    // Patterns for malicious requests (regex)
    'malicious_patterns' => [
        // SQLi
        '/(\%27)|(\')|(\-\-)|(\%23)|(\#)/i', // Basic SQL injection attempts
        '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i', // More SQL injection
        
        // XSS
        '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i', // Basic XSS attempts
        '/((\%3C)|<)((\%69)|i|(\%49))((\%6D)|m|(\%4D))((\%67)|g|(\%47))[^\n]+((\%3E)|>)/i', // IMG XSS
        
        // Path traversal
        '/\.\.\//i', // Simple path traversal
        '/\.\.\\\/i', // Windows path traversal
        
        // Command injection
        '/;.*(;|&&|\|\|)/i', // Command separators
        '/\$\([^)]*\)/i', // Command substitution
        '/`[^`]*`/i', // Backtick command substitution
        
        // File inclusion
        '/(php|pht|phtml|phps|php5|php7|php8|inc|phar)/i', // PHP file extensions in URLs
        
        // Remote file inclusion
        '/=.*(https?|ftp|php|data|file):/i', // URL protocol in parameter values
        
        // DoS prevention
        '/^.{1000,}$/i', // Extremely long input parameters (over 1000 chars)
    ],
    
    // Allowed URI patterns (regex)
    'allowed_uri_patterns' => [
        // Seller profile URLs
        '#^/iad/kaufen-und-verkaufen/verkaeuferprofil/[0-9]+/?$#i',
        
        // Product URLs
        '#^/iad/kaufen-und-verkaufen/d/[\w-]+-[0-9]+/?$#i',
        
        // Static files
        '#^/[\w-]+\.(jpg|jpeg|png|gif|css|js|svg|xml|pdf|txt|html)$#i',
        
        // Admin area (with subdirectories)
        '#^/admin/.*$#i',
        
        // Seller profiles
        '#^/[\w\.-]+/?$#',
        
        // Product under seller
        '#^/[\w\.-]+/[\w-]+-[0-9]+/?$#',
        
        // API endpoints
        '#^/api/v[0-9]+/.*$#i',
    ],
    
    // Input validation rules for specific request parameters
    'parameter_rules' => [
        // Seller ID validation
        'seller_id' => [
            'type' => 'pattern',
            'pattern' => '/^[0-9]+$/',
            'min_length' => 5,
            'max_length' => 15
        ],
        
        // Product ID validation
        'product_id' => [
            'type' => 'pattern',
            'pattern' => '/^[0-9]+$/',
            'min_length' => 5,
            'max_length' => 15
        ],
        
        // Slug validation
        'slug' => [
            'type' => 'pattern',
            'pattern' => '/^[\w-]+$/',
            'min_length' => 3,
            'max_length' => 100
        ],
        
        // Email validation
        'email' => [
            'type' => 'email',
            'max_length' => 255
        ],
        
        // Search query validation
        'query' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 200,
            'sanitize' => 'htmlspecialchars'
        ],
        
        // Pagination parameters
        'page' => [
            'type' => 'int',
            'min' => 1,
            'max' => 1000
        ],
        
        'limit' => [
            'type' => 'int',
            'min' => 1,
            'max' => 100
        ],
    ],
    
    // Environment-specific configurations
    'environments' => [
        'development' => [
            'max_header_length' => 16384, // Larger for development
            'max_query_params' => 200, // More for development
            'malicious_patterns' => [
                // Fewer patterns for development to make testing easier
                '/(\%27)|(\')|(\-\-)|(\%23)|(\#)/i', // Basic SQL injection attempts
                '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i', // Basic XSS attempts
            ]
        ],
        'testing' => [
            'max_header_length' => 16384,
            'max_query_params' => 200,
            // No malicious patterns in testing to allow all test cases
            'malicious_patterns' => []
        ],
        'staging' => [
            // Same as production, just as a reference
            'max_header_length' => 8192,
            'max_query_params' => 100
        ],
        'production' => [
            // Production values are defined in the base config
        ]
    ]
];

// Apply environment-specific overrides
if (isset($config['environments'][$environment])) {
    $config = array_replace_recursive($config, $config['environments'][$environment]);
}

// Remove environments from final config to avoid confusion
unset($config['environments']);

return $config;

