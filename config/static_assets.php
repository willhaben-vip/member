<?php
/**
 * Static Assets Configuration
 *
 * This file contains configuration for static asset handling,
 * including caching directives and compression settings.
 */

// Default configuration
$config = [
    // Cache-Control directives by file type
    'cache_control' => [
        // Default caching for all files
        'default' => 'public, max-age=3600', // 1 hour
        
        // File extension specific caching
        'extensions' => [
            // Images - long cache
            'jpg' => 'public, max-age=2592000', // 30 days
            'jpeg' => 'public, max-age=2592000', // 30 days
            'png' => 'public, max-age=2592000', // 30 days
            'gif' => 'public, max-age=2592000', // 30 days
            'ico' => 'public, max-age=2592000', // 30 days
            'svg' => 'public, max-age=2592000', // 30 days
            'webp' => 'public, max-age=2592000', // 30 days
            
            // CSS/JS - medium cache
            'css' => 'public, max-age=604800', // 7 days
            'js' => 'public, max-age=604800', // 7 days
            
            // Fonts - long cache
            'woff' => 'public, max-age=2592000', // 30 days
            'woff2' => 'public, max-age=2592000', // 30 days
            'ttf' => 'public, max-age=2592000', // 30 days
            'eot' => 'public, max-age=2592000', // 30 days
            
            // Documents - short cache
            'pdf' => 'public, max-age=86400', // 1 day
            'doc' => 'public, max-age=86400', // 1 day
            'docx' => 'public, max-age=86400', // 1 day
            'xls' => 'public, max-age=86400', // 1 day
            'xlsx' => 'public, max-age=86400', // 1 day
            
            // Data files - no cache
            'json' => 'no-store, no-cache, must-revalidate, max-age=0',
            'xml' => 'public, max-age=3600', // 1 hour
            
            // HTML - shorter cache
            'html' => 'public, max-age=3600', // 1 hour
            'htm' => 'public, max-age=3600', // 1 hour
        ],
    ],
    
    // ETag Configuration
    'etag' => [
        'enabled' => true,
        'strength' => 'strong', // 'strong' or 'weak'
    ],
    
    // Compression settings
    'compression' => [
        'enabled' => true,
        'level' => 6, // Compression level (1-9)
        'types' => [
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml',
            'text/xml',
            'image/svg+xml',
            'text/plain',
        ],
    ],
    
    // Versioning
    'versioning' => [
        'enabled' => true,
        'parameter' => 'v',
    ],
];

return $config;

