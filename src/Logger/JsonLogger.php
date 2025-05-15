<?php
/**
 * JSON structured Logger implementation
 * 
 * Provides structured JSON logging with request ID tracking
 * and proper file management for production environments.
 */

namespace Willhaben\Logger;

/**
 * Production-ready JSON structured logger
 */
class JsonLogger implements LoggerInterface
{
    /**
     * Log levels severity map
     * 
     * @var array<string, int>
     */
    protected const LOG_LEVELS = [
        'emergency' => 800,
        'alert'     => 700,
        'critical'  => 600,
        'error'     => 500,
        'warning'   => 400,
        'notice'    => 300,
        'info'      => 200,
        'debug'     => 100,
    ];

    /**
     * Path to log file
     * 
     * @var string
     */
    protected $logPath;

    /**
     * Minimum log level to record
     * 
     * @var string
     */
    protected $minLevel;

    /**
     * Request ID for tracking
     * 
     * @var string
     */
    protected $requestId;

    /**
     * Constructor
     * 
     * @param string $logPath Path to log file
     * @param string $minLevel Minimum log level to record
     */
    public function __construct(string $logPath, string $minLevel = 'debug')
    {
        $this->logPath = $logPath;
        $this->minLevel = $minLevel;
        $this->requestId = $this->generateRequestId();
        
        // Create log directory if it doesn't exist
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Ensure log file is writable
        if (!file_exists($logPath)) {
            touch($logPath);
            chmod($logPath, 0644);
        }
    }

    /**
     * Generate unique request ID
     * 
     * @return string
     */
    protected function generateRequestId(): string
    {
        // Use existing request ID from header if available
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }
        
        // Generate a new request ID using a UUID v4
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Get the current request ID
     * 
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * Format log message as JSON
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     * @return string
     */
    protected function formatJsonLog(string $level, string $message, array $context = []): string
    {
        $logEntry = [
            'timestamp'  => date('c'), // ISO 8601 format
            'level'      => $level,
            'message'    => $message,
            'request_id' => $this->requestId,
            'context'    => $context,
        ];
        
        // Add server info in debug mode
        if ($level === 'debug') {
            $logEntry['server'] = [
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'method'    => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'uri'       => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ];
        }
        
        return json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL;
    }

    /**
     * Write log entry to file
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function writeLog(string $level, string $message, array $context = []): void
    {
        // Skip logging if level is below minimum
        if (self::LOG_LEVELS[$level] < self::LOG_LEVELS[$this->minLevel]) {
            return;
        }
        
        $jsonLog = $this->formatJsonLog($level, $message, $context);
        
        // Write to log file with error handling
        try {
            file_put_contents($this->logPath, $jsonLog, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // If we can't write to log file, try error_log as fallback
            error_log("Failed to write to log file {$this->logPath}: {$e->getMessage()}");
            error_log($jsonLog);
        }
    }

    /**
     * System is unusable.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = []): void
    {
        $this->writeLog('emergency', $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = []): void
    {
        $this->writeLog('alert', $message, $context);
    }

    /**
     * Critical conditions.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = []): void
    {
        $this->writeLog('critical', $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = []): void
    {
        $this->writeLog('error', $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        $this->writeLog('warning', $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = []): void
    {
        $this->writeLog('notice', $message, $context);
    }

    /**
     * Interesting events.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        $this->writeLog('info', $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        $this->writeLog('debug', $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        // Validate log level
        if (!isset(self::LOG_LEVELS[$level])) {
            $level = 'info';
        }
        
        $this->writeLog($level, $message, $context);
    }
}

