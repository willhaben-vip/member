<?php
/**
 * Security Configuration
 *
 * This file contains security-related configuration settings.
 * It defines various security headers and policies for the application.
 */

// Determine environment
$environment = getenv('APP_ENV') ?: 'production';

// Base configuration for all environments
$config = [
    // Security headers to apply to all responses
    'headers' => [
        // Protection against clickjacking
        'X-Frame-Options' => 'DENY',
        
        // Protection against MIME type sniffing
        'X-Content-Type-Options' => 'nosniff',
        
        // Protection against XSS attacks
        'X-XSS-Protection' => '1; mode=block',
        
        // Referrer Policy (control referrer information)
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        
        // HTTP Strict Transport Security (force HTTPS)
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
        
        // Permissions Policy (formerly Feature Policy)
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=(), autoplay=(), payment=(), usb=(), screen-wake-lock=(), display-capture=(), accelerometer=(), ambient-light-sensor=(), battery=(), execution-while-not-rendered=(), execution-while-out-of-viewport=(), gamepad=(), gyroscope=(), hid=(), idle-detection=(), magnetometer=(), midi=(), navigation-override=(), picture-in-picture=(), publickey-credentials-get=(), serial=(), sync-xhr=(), web-share=()',
        
        // Cross-Origin-Embedder-Policy
        'Cross-Origin-Embedder-Policy' => 'require-corp',
        
        // Cross-Origin-Opener-Policy
        'Cross-Origin-Opener-Policy' => 'same-origin',
        
        // Cross-Origin-Resource-Policy
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],
    
    // IP-based access control for critical endpoints
    'ip_access_control' => [
        'enabled' => true,
        'endpoints' => [
            '/admin/' => [
                'whitelist' => [
                    '127.0.0.1',             // localhost
                    '::1',                   // localhost IPv6
                    '192.168.0.0/16',        // internal network
                    '10.0.0.0/8',            // internal network
                ],
                'action' => 'deny',          // deny all except whitelist
                'error_code' => 403,         // Forbidden
                'log_level' => 'warning'     // Log level for violations
            ],
            '/metrics' => [
                'whitelist' => [
                    '127.0.0.1',             // localhost
                    '::1',                   // localhost IPv6
                    '192.168.0.0/16',        // internal network
                ],
                'action' => 'deny',          // deny all except whitelist
                'error_code' => 403,         // Forbidden
                'log_level' => 'warning'     // Log level for violations
            ],
            '/sysadmin/' => [
                'whitelist' => [
                    '127.0.0.1',             // localhost
                ],
                'action' => 'deny',          // deny all except whitelist
                'error_code' => 403,         // Forbidden
                'log_level' => 'warning'     // Log level for violations
            ]
        ]
    ],
    
    // Content Security Policy (CSP)
    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'strict-dynamic'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'https://*.willhaben.at'],
        'font-src' => ["'self'", 'https://fonts.gstatic.com'],
        'connect-src' => ["'self'", 'https://*.willhaben.at'],
        'frame-src' => ["'none'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'manifest-src' => ["'self'"],
        'media-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
        'upgrade-insecure-requests' => true,
        'block-all-mixed-content' => true,
        'report-uri' => ['/api/csp-report'], // Endpoint to receive CSP violation reports
        'report-to' => 'csp-endpoint',       // Reporting group for CSP violations
    ],
    
    // Report-To header configuration (for CSP reporting)
    'report_to' => [
        'groups' => [
            [
                'name' => 'csp-endpoint',
                'max_age' => 10886400,        // 126 days
                'endpoints' => [
                    ['url' => '/api/csp-report']
                ],
                'include_subdomains' => true
            ]
        ]
    ],
    
    // Environments-specific overrides
    'environments' => [
        'development' => [
            'headers' => [
                // Development doesn't need strict HSTS
                'Strict-Transport-Security' => null,
            ],
            'csp' => [
                // Allow more sources in development
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
                'upgrade-insecure-requests' => false,
                'block-all-mixed-content' => false,
            ],
        ],
        'testing' => [
            'headers' => [
                // Testing doesn't need strict HSTS
                'Strict-Transport-Security' => null,
            ],
            'csp' => [
                // Allow more sources in testing
                'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
                'upgrade-insecure-requests' => false,
            ],
        ],
        'staging' => [
            'headers' => [
                // Less strict HSTS for staging
                'Strict-Transport-Security' => 'max-age=86400',
            ],
        ],
        'production' => [
            // Production uses base config (most strict)
        ],
    ],
];

// Apply environment-specific overrides
if (isset($config['environments'][$environment])) {
    if (isset($config['environments'][$environment]['headers'])) {
        foreach ($config['environments'][$environment]['headers'] as $header => $value) {
            if ($value === null) {
                unset($config['headers'][$header]);
            } else {
                $config['headers'][$header] = $value;
            }
        }
    }
    
    if (isset($config['environments'][$environment]['csp'])) {
        foreach ($config['environments'][$environment]['csp'] as $directive => $value) {
            $config['csp'][$directive] = $value;
        }
    }
}

// Remove environments from final config
unset($config['environments']);

// Build the CSP header value
$cspHeader = '';
foreach ($config['csp'] as $directive => $values) {
    if ($directive === 'upgrade-insecure-requests' && $values === true) {
        $cspHeader .= 'upgrade-insecure-requests; ';
    } elseif ($directive === 'block-all-mixed-content' && $values === true) {
        $cspHeader .= 'block-all-mixed-content; ';
    } elseif (is_array($values) && !empty($values)) {
        $cspHeader .= $directive . ' ' . implode(' ', $values) . '; ';
    }
}

// Add the CSP header to the headers array
$config['headers']['Content-Security-Policy'] = trim($cspHeader);

return $config;

