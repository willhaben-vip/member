<?php
/**
 * File-based Cache Implementation
 * 
 * Implements caching using the file system as a fallback when Redis is not available.
 */

namespace Willhaben\Cache;

/**
 * File-based cache implementation
 */
class FileCache implements CacheInterface
{
    /**
     * Cache directory
     *
     * @var string
     */
    protected $cacheDir;
    
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
     * @param string $cacheDir Cache directory path
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     * @param int $defaultTtl Default TTL in seconds
     */
    public function __construct(string $cacheDir, \Willhaben\Logger\LoggerInterface $logger, int $defaultTtl = 3600)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->logger = $logger;
        $this->defaultTtl = $defaultTtl;
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                $this->logger->error('Failed to create cache directory', ['directory' => $this->cacheDir]);
            }
        }
    }
    
    /**
     * Get cache file path for a key
     *
     * @param string $key Cache key
     * @return string File path
     */
    protected function getFilePath(string $key): string
    {
        // Convert the key to a valid filename
        $filename = md5($key) . '.cache';
        
        // Use the first 2 characters of the hash for a subdirectory to avoid too many files in one directory
        $subDir = substr($filename, 0, 2);
        $fullDir = $this->cacheDir . '/' . $subDir;
        
        // Create subdirectory if needed
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }
        
        return $fullDir . '/' . $filename;
    }
    
    /**
     * Get a value from the cache
     *
     * @param string $key Cache key
     * @return mixed|null The cached value or null if not found/expired
     */
    public function get(string $key)
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        try {
            $content = file_get_contents($filePath);
            
            if ($content === false) {
                return null;
            }
            
            $data = json_decode($content, true);
            
            if (!is_array($data) || !isset($data['expires']) || !isset($data['value'])) {
                // Invalid cache format
                $this->delete($key);
                return null;
            }
            
            // Check if expired
            if ($data['expires'] > 0 && $data['expires'] < time()) {
                // Cache expired
                $this->delete($key);
                return null;
            }
            
            return $data['value'];
        } catch (\Throwable $e) {
            $this->logger->error('File cache get error: ' . $e->getMessage(), [
                'key' => $key,
                'file' => $filePath,
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
        $filePath = $this->getFilePath($key);
        
        try {
            $ttl = $ttl ?? $this->defaultTtl;
            
            // Calculate expiry time (0 = no expiry)
            $expires = $ttl > 0 ? time() + $ttl : 0;
            
            $data = [
                'expires' => $expires,
                'value' => $value,
                'created' => time()
            ];
            
            $content = json_encode($data);
            
            if ($content === false) {
                $this->logger->error('Failed to encode value for caching', [
                    'key' => $key,
                    'error' => json_last_error_msg()
                ]);
                return false;
            }
            
            $result = file_put_contents($filePath, $content, LOCK_EX);
            
            if ($result === false) {
                $this->logger->error('Failed to write cache file', [
                    'key' => $key,
                    'file' => $filePath
                ]);
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('File cache set error: ' . $e->getMessage(), [
                'key' => $key,
                'file' => $filePath,
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
        return $this->get($key) !== null;
    }
    
    /**
     * Delete a value from the cache
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return true; // Already doesn't exist
        }
        
        try {
            return unlink($filePath);
        } catch (\Throwable $e) {
            $this->logger->error('File cache delete error: ' . $e->getMessage(), [
                'key' => $key,
                'file' => $filePath,
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
        try {
            $this->clearDirectory($this->cacheDir);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('File cache clear error: ' . $e->getMessage(), [
                'directory' => $this->cacheDir,
                'exception' => get_class($e)
            ]);
            return false;
        }
    }
    
    /**
     * Recursively clear a directory
     *
     * @param string $dir Directory to clear
     * @return void
     */
    protected function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                $this->clearDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
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
        $result = [];
        
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $result[$key] = $value;
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
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
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
        $success = true;
        
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }
}
