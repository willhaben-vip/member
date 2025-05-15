<?php
/**
 * Redis-based Rate Limiter
 *
 * Implements a sliding window algorithm for rate limiting API requests
 * using Redis as a distributed storage backend.
 */

namespace Willhaben\Security;

/**
 * RateLimiter class for protecting endpoints from abuse
 */
class RateLimiter
{
    /**
     * Redis client
     *
     * @var \Redis|null
     */
    protected $redis;

    /**
     * Logger instance
     *
     * @var \Willhaben\Logger\LoggerInterface
     */
    protected $logger;

    /**
     * Rate limit configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Is rate limiting enabled
     *
     * @var bool
     */
    protected $enabled;

    /**
     * Constructor
     *
     * @param array $config Rate limiting configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     */
    public function __construct(array $config, \Willhaben\Logger\LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->enabled = $config['enabled'] ?? true;
        
        // Initialize Redis connection if enabled
        if ($this->enabled) {
            $this->initRedis();
        }
    }

    /**
     * Initialize Redis connection
     *
     * @return void
     */
    protected function initRedis(): void
    {
        if (!class_exists('Redis')) {
            $this->logger->warning('Redis extension not installed. Rate limiting disabled.');
            $this->enabled = false;
            return;
        }

        try {
            $this->redis = new \Redis();
            
            $connectTimeout = $this->config['connect_timeout'] ?? 1;
            
            // Use unix socket if available, otherwise use host/port
            if (!empty($this->config['socket'])) {
                $connected = $this->redis->connect($this->config['socket']);
            } else {
                $host = $this->config['host'] ?? '127.0.0.1';
                $port = $this->config['port'] ?? 6379;
                $connected = $this->redis->connect($host, $port, $connectTimeout);
            }
            
            if (!$connected) {
                throw new \Exception('Failed to connect to Redis server');
            }
            
            // Set auth if configured
            if (!empty($this->config['auth'])) {
                $this->redis->auth($this->config['auth']);
            }
            
            // Select database if configured
            if (isset($this->config['database'])) {
                $this->redis->select($this->config['database']);
            }
            
            // Set prefix for keys
            $prefix = $this->config['key_prefix'] ?? 'rate_limit:';
            $this->redis->setOption(\Redis::OPT_PREFIX, $prefix);
            
            $this->logger->debug('Redis connection established for rate limiting');
        } catch (\Throwable $e) {
            $this->logger->error('Redis connection failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->redis = null;
            $this->enabled = false;
        }
    }

    /**
     * Check if the current request exceeds rate limits
     *
     * @param string $route Route identifier
     * @param string|null $identifier Custom identifier (defaults to client IP)
     * @return bool True if rate limit exceeded, false otherwise
     */
    public function isLimitExceeded(string $route, ?string $identifier = null): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false; // Rate limiting disabled or Redis unavailable
        }
        
        // Get client identifier (IP address if not specified)
        $clientId = $identifier ?? $this->getClientIp();
        
        // Get rate limit config for this route
        $limitConfig = $this->getRouteLimitConfig($route);
        if (!$limitConfig) {
            return false; // No rate limit defined for this route
        }
        
        try {
            $maxRequests = $limitConfig['requests'];
            $period = $limitConfig['period']; // in seconds
            
            // Create a unique key for this client and route
            $key = "{$route}:{$clientId}";
            
            // Current timestamp
            $now = time();
            
            // Remove requests older than the window period
            $this->redis->zRemRangeByScore($key, 0, $now - $period);
            
            // Count remaining requests in window
            $requestCount = $this->redis->zCard($key);
            
            // Check if limit is exceeded
            if ($requestCount >= $maxRequests) {
                $this->logger->warning('Rate limit exceeded', [
                    'client' => $clientId,
                    'route' => $route,
                    'limit' => $maxRequests,
                    'period' => $period,
                    'count' => $requestCount
                ]);
                return true;
            }
            
            // Add current request to window with score as timestamp
            $this->redis->zAdd($key, $now, $now . ':' . uniqid('', true));
            
            // Set key expiration to ensure cleanup
            $this->redis->expire($key, $period * 2);
            
            $this->logger->debug('Rate limit checked', [
                'client' => $clientId,
                'route' => $route,
                'limit' => $maxRequests,
                'period' => $period,
                'count' => $requestCount + 1,
                'remaining' => $maxRequests - ($requestCount + 1)
            ]);
            
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Rate limiting error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'route' => $route,
                'client' => $clientId
            ]);
            
            // Fail open in case of errors
            return false;
        }
    }
    
    /**
     * Get rate limit information for a route
     * 
     * @param string $route Route identifier
     * @param string|null $identifier Custom identifier (defaults to client IP)
     * @return array Rate limit information including limit, remaining, and reset time
     */
    public function getRateLimitInfo(string $route, ?string $identifier = null): array
    {
        if (!$this->enabled || !$this->redis) {
            return [
                'limit' => 0,
                'remaining' => 0,
                'reset' => 0
            ];
        }
        
        // Get client identifier (IP address if not specified)
        $clientId = $identifier ?? $this->getClientIp();
        
        // Get rate limit config for this route
        $limitConfig = $this->getRouteLimitConfig($route);
        if (!$limitConfig) {
            return [
                'limit' => 0,
                'remaining' => 0,
                'reset' => 0
            ];
        }
        
        try {
            $maxRequests = $limitConfig['requests'];
            $period = $limitConfig['period']; // in seconds
            
            // Create a unique key for this client and route
            $key = "{$route}:{$clientId}";
            
            // Current timestamp
            $now = time();
            
            // Remove requests older than the window period
            $this->redis->zRemRangeByScore($key, 0, $now - $period);
            
            // Count remaining requests in window
            $requestCount = $this->redis->zCard($key);
            
            // Calculate next reset time
            $oldestRequest = $this->redis->zRange($key, 0, 0, true);
            if (!empty($oldestRequest)) {
                $oldestTimestamp = (int)explode(':', key($oldestRequest))[0];
                $resetTime = $oldestTimestamp + $period;
            } else {
                $resetTime = $now + $period;
            }
            
            return [
                'limit' => $maxRequests,
                'remaining' => max(0, $maxRequests - $requestCount),
                'reset' => $resetTime
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Rate limit info error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'route' => $route,
                'client' => $clientId
            ]);
            
            return [
                'limit' => 0,
                'remaining' => 0,
                'reset' => 0
            ];
        }
    }
    
    /**
     * Set rate limit headers on the response
     * 
     * @param string $route Route identifier
     * @param string|null $identifier Custom identifier (defaults to client IP)
     * @return void
     */
    public function setRateLimitHeaders(string $route, ?string $identifier = null): void
    {
        if (!$this->enabled) {
            return;
        }
        
        $rateLimitInfo = $this->getRateLimitInfo($route, $identifier);
        
        if ($rateLimitInfo['limit'] > 0) {
            header('X-RateLimit-Limit: ' . $rateLimitInfo['limit']);
            header('X-RateLimit-Remaining: ' . $rateLimitInfo['remaining']);
            header('X-RateLimit-Reset: ' . $rateLimitInfo['reset']);
        }
    }
    
    /**
     * Get rate limit configuration for a route
     * 
     * @param string $route Route identifier
     * @return array|null Rate limit configuration or null if not found
     */
    protected function getRouteLimitConfig(string $route): ?array
    {
        // Check for exact route match
        if (isset($this->config['routes'][$route])) {
            return $this->config['routes'][$route];
        }
        
        // Check for pattern matches
        foreach ($this->config['patterns'] ?? [] as $pattern => $limits) {
            if (preg_match($pattern, $route)) {
                return $limits;
            }
        }
        
        // Fall back to default limits
        if (isset($this->config['default'])) {
            return $this->config['default'];
        }
        
        return null;
    }
    
    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    protected function getClientIp(): string
    {
        // Check for proxy forwarded IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Take the first IP in the list
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        // Sanitize and validate IP
        $ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
        return $ip ?: '0.0.0.0';
    }
    
    /**
     * Handle exceeded rate limit
     * 
     * @param string $route Route identifier
     * @return void
     */
    public function handleLimitExceeded(string $route): void
    {
        $rateLimitInfo = $this->getRateLimitInfo($route);
        
        // Set rate limit headers
        header('X-RateLimit-Limit: ' . $rateLimitInfo['limit']);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $rateLimitInfo['reset']);
        
        // Set 429 Too Many Requests status code
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: ' . max(1, $rateLimitInfo['reset'] - time()));
        header('Content-Type: application/json');
        
        // Send error response
        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
            'status' => 429
        ]);
        exit;
    }
}

