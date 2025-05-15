<?php
/**
 * Logging Configuration
 *
 * This file contains configuration settings for the logging system.
 * Different values can be set for different environments.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Default configuration
$config = [
    // Log file path - different for each environment
    'path' => [
        'development' => __DIR__ . '/../var/logs/redirect_dev.log',
        'testing'     => __DIR__ . '/../var/logs/redirect_test.log', 
        'staging'     => __DIR__ . '/../var/logs/redirect_staging.log',
        'production'  => '/var/log/willhaben/redirect.log'
    ],
    
    // Minimum log level for each environment
    'level' => [
        'development' => 'debug',     // Debug level for development
        'testing'     => 'debug',     // Debug level for testing
        'staging'     => 'info',      // Info level for staging
        'production'  => 'notice'     // Notice level and above for production
    ],
    
    // Log rotation settings
    'rotation' => [
        'max_size'  => 10485760,      // 10MB
        'max_files' => 10             // Keep 10 rotated files
    ]
];

// Get environment-specific settings
$logPath = $config['path'][$environment] ?? $config['path']['production'];
$logLevel = $config['level'][$environment] ?? $config['level']['production'];
$rotationSettings = $config['rotation'];

// Define constants for use in the application
define('LOG_PATH', $logPath);
define('LOG_LEVEL', $logLevel);
define('LOG_ROTATION_MAX_SIZE', $rotationSettings['max_size']);
define('LOG_ROTATION_MAX_FILES', $rotationSettings['max_files']);

return $config;

