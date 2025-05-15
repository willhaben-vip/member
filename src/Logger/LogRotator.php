<?php
/**
 * Log Rotation Utility
 * 
 * Provides log rotation capabilities for production environments
 */

namespace Willhaben\Logger;

/**
 * Log rotation handler for production logs
 */
class LogRotator
{
    /**
     * Path to the log file
     * 
     * @var string
     */
    protected $logPath;
    
    /**
     * Maximum log file size in bytes before rotation (default: 10MB)
     * 
     * @var int
     */
    protected $maxSize;
    
    /**
     * Number of backup files to keep
     * 
     * @var int
     */
    protected $maxFiles;
    
    /**
     * Constructor
     * 
     * @param string $logPath Path to log file
     * @param int $maxSize Maximum log file size in bytes before rotation (default: 10MB)
     * @param int $maxFiles Number of backup files to keep (default: 5)
     */
    public function __construct(string $logPath, int $maxSize = 10485760, int $maxFiles = 5)
    {
        $this->logPath = $logPath;
        $this->maxSize = $maxSize;
        $this->maxFiles = $maxFiles;
    }
    
    /**
     * Check if rotation is needed and rotate if necessary
     * 
     * @return bool True if rotation was performed
     */
    public function checkAndRotate(): bool
    {
        if (!file_exists($this->logPath)) {
            return false;
        }
        
        if (filesize($this->logPath) < $this->maxSize) {
            return false;
        }
        
        return $this->rotate();
    }
    
    /**
     * Rotate log files
     * 
     * @return bool True if rotation was successful
     */
    public function rotate(): bool
    {
        try {
            // Remove oldest log file if max files reached
            $oldestLog = $this->logPath . '.' . $this->maxFiles;
            if (file_exists($oldestLog)) {
                unlink($oldestLog);
            }
            
            // Shift existing log files
            for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
                $oldFile = $this->logPath . '.' . $i;
                $newFile = $this->logPath . '.' . ($i + 1);
                
                if (file_exists($oldFile)) {
                    rename($oldFile, $newFile);
                }
            }
            
            // Rename current log file
            rename($this->logPath, $this->logPath . '.1');
            
            // Create a new empty log file with correct permissions
            touch($this->logPath);
            chmod($this->logPath, 0644);
            
            return true;
        } catch (\Throwable $e) {
            error_log("Failed to rotate log file {$this->logPath}: {$e->getMessage()}");
            return false;
        }
    }
}
