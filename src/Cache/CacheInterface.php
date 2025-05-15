<?php
/**
 * Cache Interface
 * 
 * Defines the contract for cache implementations.
 */

namespace Willhaben\Cache;

/**
 * Interface for cache implementations
 */
interface CacheInterface
{
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @return mixed|null The cached value or null if not found/expired
     */
    public function get(string $key);
    
    /**
     * Set a value in the cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value, ?int $ttl = null): bool;
    
    /**
     * Check if a key exists in the cache
     *
     * @param string $key Cache key
     * @return bool True if key exists and not expired, false otherwise
     */
    public function has(string $key): bool;
    
    /**
     * Delete a value from the cache
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool;
    
    /**
     * Clear all values from the cache
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool;
    
    /**
     * Get multiple values from the cache
     *
     * @param array $keys Array of cache keys
     * @return array Array of key/value pairs for found items
     */
    public function getMultiple(array $keys): array;
    
    /**
     * Set multiple values in the cache
     *
     * @param array $values Array of key/value pairs to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool True on success, false on failure
     */
    public function setMultiple(array $values, ?int $ttl = null): bool;
    
    /**
     * Delete multiple values from the cache
     *
     * @param array $keys Array of cache keys to delete
     * @return bool True on success, false on failure
     */
    public function deleteMultiple(array $keys): bool;
}

