<?php
/**
 * Cache Manager Service
 * 
 * Manages multiple cache implementations and provides a simple interface for the application.
 */

namespace Willhaben\Cache;

/**
 * CacheManager service for handling caching operations
 */
class CacheManager implements CacheInterface
{
    /**
     * Primary cache implementation
     *
     * @var CacheInterface
     */
    protected $primaryCache;
    
    /**
     * Fallback cache implementation
     *
     * @var CacheInterface|null
     */
    protected $fallbackCache;
    
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
     * Constructor
     *
     * @param CacheInterface $primaryCache Primary cache implementation
     * @param CacheInterface|null $fallbackCache Fallback cache implementation
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     * @param int $defaultTtl Default TTL in seconds
     */
    public function __construct(
        CacheInterface $primaryCache,
        ?CacheInterface $fallbackCache,
        \Willhaben\Logger\LoggerInterface $logger,
        int $defaultTtl = 3600
    ) {
        $this->primaryCache = $primaryCache;
        $this->fallbackCache = $fallbackCache;
        $this->logger = $logger;
        $this->defaultTtl = $defaultTtl;
    }
    
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @return mixed|null The cached value or null if not found/expired
     */
    public function get(string $key)
    {
        // Try primary cache first
        $value = $this->primaryCache->get($key);
        
        // If value not found in primary cache, try fallback
        if ($value === null && $this->fallbackCache !== null) {
            $value = $this->fallbackCache->get($key);
            
            // If found in fallback, populate primary cache
            if ($value !== null) {
                $this->logger->debug('Cache miss in primary, hit in fallback', ['key' => $key]);
                $this->primaryCache->set($key, $value);
            }
        }
        
        return $value;
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
        $ttl = $ttl ?? $this->defaultTtl;
        $success = $this->primaryCache->set($key, $value, $ttl);
        
        // Also set in fallback if available
        if ($this->fallbackCache !== null) {
            $fallbackSuccess = $this->fallbackCache->set($key, $value, $ttl);
            
            if (!$fallbackSuccess) {
                $this->logger->warning('Failed to set value in fallback cache', ['key' => $key]);
            }
        }
        
        return $success;
    }
    
    /**
     * Check if a key exists in the cache
     *
     * @param string $key Cache key
     * @return bool True if key exists and not expired, false otherwise
     */
    public function has(string $key): bool
    {
        // Check primary cache first
        if ($this->primaryCache->has($key)) {
            return true;
        }
        
        // If not in primary, check fallback
        return $this->fallbackCache !== null && $this->fallbackCache->has($key);
    }
    
    /**
     * Delete a value from the cache
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool
    {
        $success = $this->primaryCache->delete($key);
        
        // Also delete from fallback if available
        if ($this->fallbackCache !== null) {
            $fallbackSuccess = $this->fallbackCache->delete($key);
            
            if (!$fallbackSuccess) {
                $this->logger->warning('Failed to delete value from fallback cache', ['key' => $key]);
            }
        }
        
        return $success;
    }
    
    /**
     * Clear all values from the cache
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool
    {
        $success = $this->primaryCache->clear();
        
        // Also clear fallback if available
        if ($this->fallbackCache !== null) {
            $fallbackSuccess = $this->fallbackCache->clear();
            
            if (!$fallbackSuccess) {
                $this->logger->warning('Failed to clear fallback cache');
            }
        }
        
        return $success;
    }
    
    /**
     * Get multiple values from the cache
     *
     * @param array $keys Array of cache keys
     * @return array Array of key/value pairs for found items
     */
    public function getMultiple(array $keys): array
    {
        // Try primary cache first
        $result = $this->primaryCache->getMultiple($keys);
        
        // If fallback is available, get missing keys from it
        if ($this->fallbackCache !== null && count($result) < count($keys)) {
            $missingKeys = array_diff($keys, array_keys($result));
            
            if (!empty($missingKeys)) {
                $fallbackValues = $this->fallbackCache->getMultiple($missingKeys);
                
                if (!empty($fallbackValues)) {
                    $this->logger->debug('Cache miss in primary, hit in fallback for multiple keys', [
                        'found_keys' => array_keys($fallbackValues)
                    ]);
                    
                    // Populate primary cache with values from fallback
                    $this->primaryCache->setMultiple($fallbackValues);
                    
                    // Merge results
                    $result = array_merge($result, $fallbackValues);
                }
            }
        }
        
        return $result;
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
        $ttl = $ttl ?? $this->defaultTtl;
        $success = $this->primaryCache->setMultiple($values, $ttl);
        
        // Also set in fallback if available
        if ($this->fallbackCache !== null) {
            $fallbackSuccess = $this->fallbackCache->setMultiple($values, $ttl);
            
            if (!$fallbackSuccess) {
                $this->logger->warning('Failed to set multiple values in fallback cache');
            }
        }
        
        return $success;
    }
    
    /**
     * Delete multiple values from the cache
     *
     * @param array $keys Array of cache keys to delete
     * @return bool True on success, false on failure
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = $this->primaryCache->deleteMultiple($keys);
        
        // Also delete from fallback if available
        if ($this->fallbackCache !== null) {
            $fallbackSuccess = $this->fallbackCache->deleteMultiple($keys);
            
            if (!$fallbackSuccess) {
                $this->logger->warning('Failed to delete multiple values from fallback cache');
            }
        }
        
        return $success;
    }
    
    /**
     * Cache a seller ID lookup
     * 
     * @param string $sellerId Seller ID to cache
     * @param string|bool $slug The seller slug or false if invalid
     * @param int|null $ttl Cache TTL in seconds (null for default)
     * @return bool Success status
     */
    public function cacheSellerLookup(string $sellerId, $slug, ?int $ttl = null): bool
    {
        $key = 'seller:' . $sellerId;
        return $this->set($key, $slug, $ttl);
    }
    
    /**
     * Get cached seller slug
     * 
     * @param string $sellerId Seller ID to lookup
     * @return string|bool|null The seller slug, false if invalid, or null if not in cache
     */
    public function getSellerSlug(string $sellerId)
    {
        $key = 'seller:' . $sellerId;
        return $this->get($key);
    }
    
    /**
     * Invalidate a seller cache entry
     * 
     * @param string $sellerId Seller ID to invalidate
     * @return bool Success status
     */
    public function invalidateSellerCache(string $sellerId): bool
    {
        $key = 'seller:' . $sellerId;
        return $this->delete($key);
    }
}
