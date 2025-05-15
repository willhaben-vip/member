<?php
/**
 * Security Headers Middleware
 * 
 * Applies security headers to all responses.
 */

namespace Willhaben\Middleware;

/**
 * Middleware for applying security headers
 */
class SecurityHeadersMiddleware
{
    /**
     * Security configuration
     *
     * @var array
     */
    protected $config;
    
    /**
     * Logger instance
     *
     * @var \Willhaben\Logger\LoggerInterface
     */
    protected $logger;
    
    /**
     * Constructor
     *
     * @param array $config Security configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     */
    public function __construct(array $config, \Willhaben\Logger\LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Apply security headers to the response
     *
     * @return void
     */
    public function apply(): void
    {
        foreach ($this->config['headers'] as $name => $value) {
            if ($value !== null && !empty($value)) {
                header("{$name}: {$value}");
            }
        }
        
        $this->logger->debug('Applied security headers', [
            'headers' => array_keys($this->config['headers'])
        ]);
    }
    
    /**
     * Apply security headers for a specific context
     *
     * @param string $context The context (e.g., 'api', 'static', 'redirect')
     * @return void
     */
    public function applyForContext(string $context): void
    {
        $this->apply();
        
        // Add context-specific headers if needed
        switch ($context) {
            case 'api':
                // Specific headers for API responses
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: GET, POST');
                header('Access-Control-Allow-Headers: Content-Type, Authorization');
                break;
                
            case 'static':
                // Static files don't need some security headers
                if (headers_sent()) {
                    return;
                }
                
                // Remove CSP for static assets for better performance
                header_remove('Content-Security-Policy');
                break;
                
            case 'redirect':
                // Redirect-specific headers
                header('Vary: Accept');
                break;
        }
    }
    
    /**
     * Generate a nonce for use in Content-Security-Policy
     *
     * @return string The generated nonce
     */
    public function generateNonce(): string
    {
        $nonce = base64_encode(random_bytes(16));
        return $nonce;
    }
}

