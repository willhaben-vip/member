<?php
/**
 * Redis Cache Implementation
 * 
 * Implements caching using Redis for high-performance scenarios.
 */

namespace Willhaben\Cache;

/**
 * Redis-based cache implementation
 */
class RedisCache implements CacheInterface
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
     * Default TTL in seconds
     *
     * @var int
     */
    protected $defaultTtl;
    
    /**
     * Cache prefix
     *
     * @var string
     */
    protected $prefix;
    
    /**
     * Is Redis available
     *
     * @var bool
     */
    protected $available = false;
    
    /**
     * Constructor
     *
     * @param array $config Redis configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     * @param int $defaultTtl Default TTL in seconds
     */
    public function __construct(array $config, \Willhaben\Logger\LoggerInterface $logger, int $defaultTtl = 3600)
    {
        $this->logger = $logger;
        $this->defaultTtl = $defaultTtl;
        $this->prefix = $config['prefix'] ?? 'cache:';
        
        // Initialize Redis connection
        $this->initRedis($config);
    }
    
    /**
     * Initialize Redis connection
     *
     * @param array $config Redis configuration
     * @return void
     */
    protected function initRedis(array $config): void
    {
        if (!class_exists('Redis')) {
            $this->logger->warning('Redis extension not installed. Redis cache disabled.');
            return;
        }
        
        try {
            $this->redis = new \Redis();
            
            $connectTimeout = $config['connect_timeout'] ?? 1;
            
            // Use unix socket if available, otherwise use host/port
            if (!empty($config['socket'])) {
                $connected = $this->redis->connect($config['socket']);
            } else {
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 6379;
                $connected = $this->redis->connect($host, $port, $connectTimeout);
            }
            
            if (!$connected) {
                throw new \Exception('Failed to connect to Redis server');
            }
            
            // Set auth if configured
            if (!empty($config['auth'])) {
                $this->redis->auth($config['auth']);
            }
            
            // Select database if configured
            if (isset($config['database'])) {
                $this->redis->select($config['database']);
            }
            
            // Set prefix for keys
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
            
            $this->available = true;
            $this->logger->debug('Redis connection established for caching');
        } catch (\Throwable $e) {
            $this->logger->error('Redis connection failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->redis = null;
        }
    }
    
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @return mixed|null The cached value or null if not found/expired
     */
    public function get(string $key)
    {
        if (!$this->available || !$this->redis) {
            return null;
        }
        
        try {
            $value = $this->redis->get($key);
            
            if ($value === false) {
                return null;
            }
            
            $decoded = json_decode($value, true);
            
            // Return original value if not JSON or decoding failed
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache get error: ' . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);
            return null;
        }
    }
    
    /**
     * Set a value in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->available || !$this->redis) {
            return false;
        }
        
        try {
            // Use default TTL if not specified
            $ttl = $ttl ?? $this->defaultTtl;
            
            // Convert complex types to JSON
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
                if ($value === false) {
                    $this->logger->error('Failed to encode value for caching', [
                        'key' => $key,
                        'error' => json_last_error_msg()
                    ]);
                    return false;
                }
            }
            
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache set error: ' . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Check if a key exists in the cache
     *
     * @param string $key Cache key
     * @return bool True if key exists and not expired, false otherwise
     */
    public function has(string $key): bool
    {
        if (!$this->available || !$this->redis) {
            return false;
        }
        
        try {
            return (bool)$this->redis->exists($key);
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache has error: ' . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Delete a value from the cache
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool
    {
        if (!$this->available || !$this->redis) {
            return false;
        }
        
        try {
            return (bool)$this->redis->del($key);
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache delete error: ' . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Clear all values from the cache
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool
    {
        if (!$this->available || !$this->redis) {
            return false;
        }
        
        try {
            // Get all keys with the prefix and delete them
            $keys = $this->redis->keys('*');
            if (!empty($keys)) {
                return (bool)$this->redis->del($keys);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache clear error: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Get multiple values from the cache
     *
     * @param array $keys Array of cache keys
     * @return array Array of key/value pairs for found items
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->available || !$this->redis || empty($keys)) {
            return [];
        }
        
        try {
            $values = $this->redis->mGet($keys);
            $result = [];
            
            if (is_array($values)) {
                foreach ($keys as $i => $key) {
                    if (isset($values[$i]) && $values[$i] !== false) {
                        $decoded = json_decode($values[$i], true);
                        $result[$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $values[$i];
                    }
                }
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache getMultiple error: ' . $e->getMessage(), [
                'keys' => $keys,
                'exception' => get_class($e)
            ]);
            return [];
        }
    }
    
    /**
     * Set multiple values in the cache
     *
     * @param array $values Array of key/value pairs to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool True on success, false on failure
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->available || !$this->redis || empty($values)) {
            return false;
        }
        
        try {
            // Use default TTL if not specified
            $ttl = $ttl ?? $this->defaultTtl;
            
            // Use pipeline for better performance
            $this->redis->multi(\Redis::PIPELINE);
            
            foreach ($values as $key => $value) {
                // Convert complex types to JSON
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                    if ($value === false) {
                        $this->logger->error('Failed to encode value for caching', [
                            'key' => $key,
                            'error' => json_last_error_msg()
                        ]);
                        continue;
                    }
                }
                
                $this->redis->setex($key, $ttl, $value);
            }
            
            $this->redis->exec();
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache setMultiple error: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Delete multiple values from the cache
     *
     * @param array $keys Array of cache keys to delete
     * @return bool True on success, false on failure
     */
    public function deleteMultiple(array $keys): bool
    {
        if (!$this->available || !$this->redis || empty($keys)) {
            return false;
        }
        
        try {
            return (bool)$this->redis->del($keys);
        } catch (\Throwable $e) {
            $this->logger->error('Redis cache deleteMultiple error: ' . $e->getMessage(), [
                'keys' => $keys,
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Check if Redis is available
     *
     * @return bool True if Redis is available, false otherwise
     */
    public function isAvailable(): bool
    {
        return $this->available && $this->redis !== null;
    }
}

