<?php
/**
 * Request Validation and Sanitization System
 *
 * Provides comprehensive request validation and sanitization
 * for securing application inputs against various attacks.
 */

namespace Willhaben\Security;

/**
 * RequestValidator Class
 * 
 * Validates and sanitizes request inputs for security
 */
class RequestValidator
{
    /**
     * Logger instance
     *
     * @var \Willhaben\Logger\LoggerInterface
     */
    protected $logger;
    
    /**
     * Validation rules configuration
     *
     * @var array
     */
    protected $config;
    
    /**
     * Detected validation errors
     *
     * @var array
     */
    protected $errors = [];
    
    /**
     * CSRF token name
     *
     * @var string
     */
    protected $csrfTokenName = 'csrf_token';
    
    /**
     * Constructor
     *
     * @param array $config Validation configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     */
    public function __construct(array $config, \Willhaben\Logger\LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Validate and sanitize a request URI
     *
     * @param string $uri The request URI to validate
     * @return string|bool Sanitized URI or false if invalid
     */
    public function validateUri(string $uri)
    {
        // Basic sanitization
        $uri = filter_var($uri, FILTER_SANITIZE_URL);
        
        // Check for null bytes (potential path traversal)
        if (strpos($uri, "\0") !== false) {
            $this->addError('uri', 'URI contains null bytes');
            $this->logger->warning('Validation failure: URI contains null bytes', ['uri' => $uri]);
            return false;
        }
        
        // Check for multiple consecutive slashes
        if (strpos($uri, '//') !== false) {
            $uri = preg_replace('#/{2,}#', '/', $uri);
        }
        
        // Check for directory traversal attempts
        if (preg_match('#\.\./|/\.\./#', $uri)) {
            $this->addError('uri', 'URI contains directory traversal sequences');
            $this->logger->warning('Validation failure: URI contains directory traversal', ['uri' => $uri]);
            return false;
        }
        
        // Check for malicious patterns
        foreach ($this->config['malicious_patterns'] as $pattern) {
            if (preg_match($pattern, $uri)) {
                $this->addError('uri', 'URI matched malicious pattern');
                $this->logger->warning('Validation failure: URI matched malicious pattern', [
                    'uri' => $uri,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }
        
        // Validate against allowed URI patterns
        $valid = false;
        foreach ($this->config['allowed_uri_patterns'] as $pattern) {
            if (preg_match($pattern, $uri)) {
                $valid = true;
                break;
            }
        }
        
        if (!$valid && !empty($this->config['allowed_uri_patterns'])) {
            $this->addError('uri', 'URI does not match any allowed pattern');
            $this->logger->warning('Validation failure: URI does not match allowed patterns', ['uri' => $uri]);
            return false;
        }
        
        return $uri;
    }
    
    /**
     * Validate and sanitize a file path
     *
     * @param string $path File path to validate
     * @param string $basePath Base directory path for relative paths
     * @return string|bool Sanitized path or false if invalid
     */
    public function validateFilePath(string $path, string $basePath = '')
    {
        // Normalize directory separators
        $path = str_replace('\\', '/', $path);
        
        // Check for null bytes (potential path traversal)
        if (strpos($path, "\0") !== false) {
            $this->addError('path', 'Path contains null bytes');
            $this->logger->warning('Validation failure: Path contains null bytes', ['path' => $path]);
            return false;
        }
        
        // Check for directory traversal attempts
        if (preg_match('#\.\./|/\.\./#', $path)) {
            $this->addError('path', 'Path contains directory traversal sequences');
            $this->logger->warning('Validation failure: Path contains directory traversal', ['path' => $path]);
            return false;
        }
        
        // Remove multiple consecutive slashes
        $path = preg_replace('#/{2,}#', '/', $path);
        
        // If base path provided, ensure the path doesn't escape it
        if ($basePath) {
            $realBasePath = realpath($basePath);
            $fullPath = realpath($basePath . '/' . ltrim($path, '/'));
            
            if ($fullPath === false) {
                $this->addError('path', 'Path does not exist');
                $this->logger->warning('Validation failure: Path does not exist', ['path' => $path, 'base' => $basePath]);
                return false;
            }
            
            if (strpos($fullPath, $realBasePath) !== 0) {
                $this->addError('path', 'Path attempts to escape base directory');
                $this->logger->warning('Validation failure: Path escapes base directory', [
                    'path' => $path,
                    'base' => $basePath,
                    'full_path' => $fullPath
                ]);
                return false;
            }
            
            return $fullPath;
        }
        
        return $path;
    }
    
    /**
     * Validate HTTP headers for security issues
     *
     * @param array $headers HTTP headers to validate
     * @return bool True if headers are valid, false otherwise
     */
    public function validateHeaders(array $headers)
    {
        // Check for common header injection attacks
        foreach ($headers as $name => $value) {
            // Check for newlines in header values (header injection)
            if (preg_match('/[\r\n]/', $value)) {
                $this->addError('headers', 'Header contains newline characters');
                $this->logger->warning('Validation failure: Header contains newline characters', [
                    'header' => $name,
                    'value' => $value
                ]);
                return false;
            }
            
            // Check for overly long headers
            if (strlen($value) > $this->config['max_header_length']) {
                $this->addError('headers', 'Header exceeds maximum length');
                $this->logger->warning('Validation failure: Header exceeds maximum length', [
                    'header' => $name,
                    'length' => strlen($value),
                    'max' => $this->config['max_header_length']
                ]);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate and sanitize a parameter based on expected type
     *
     * @param mixed $value Value to validate
     * @param string $type Expected type (int, float, string, etc.)
     * @param array $options Additional validation options
     * @return mixed Sanitized value or null if invalid
     */
    public function validateParam($value, string $type, array $options = [])
    {
        switch ($type) {
            case 'int':
                return $this->validateInt($value, $options);
            
            case 'float':
                return $this->validateFloat($value, $options);
            
            case 'string':
                return $this->validateString($value, $options);
            
            case 'email':
                return $this->validateEmail($value);
            
            case 'url':
                return $this->validateUrl($value);
            
            case 'bool':
                return $this->validateBool($value);
            
            case 'ip':
                return $this->validateIp($value);
            
            case 'pattern':
                return $this->validatePattern($value, $options['pattern'] ?? '');
            
            default:
                $this->addError('param', 'Unknown validation type');
                return null;
        }
    }
    
    /**
     * Validate an integer value
     *
     * @param mixed $value Value to validate
     * @param array $options Validation options
     * @return int|null Validated integer or null if invalid
     */
    protected function validateInt($value, array $options = [])
    {
        // Convert to integer and validate
        $value = filter_var($value, FILTER_VALIDATE_INT);
        
        if ($value === false) {
            $this->addError('param', 'Value is not a valid integer');
            return null;
        }
        
        // Check min/max if provided
        if (isset($options['min']) && $value < $options['min']) {
            $this->addError('param', 'Value is below minimum allowed');
            return null;
        }
        
        if (isset($options['max']) && $value > $options['max']) {
            $this->addError('param', 'Value exceeds maximum allowed');
            return null;
        }
        
        return $value;
    }
    
    /**
     * Validate a float value
     *
     * @param mixed $value Value to validate
     * @param array $options Validation options
     * @return float|null Validated float or null if invalid
     */
    protected function validateFloat($value, array $options = [])
    {
        // Convert to float and validate
        $value = filter_var($value, FILTER_VALIDATE_FLOAT);
        
        if ($value === false) {
            $this->addError('param', 'Value is not a valid float');
            return null;
        }
        
        // Check min/max if provided
        if (isset($options['min']) && $value < $options['min']) {
            $this->addError('param', 'Value is below minimum allowed');
            return null;
        }
        
        if (isset($options['max']) && $value > $options['max']) {
            $this->addError('param', 'Value exceeds maximum allowed');
            return null;
        }
        
        return $value;
    }
    
    /**
     * Validate a string value
     *
     * @param mixed $value Value to validate
     * @param array $options Validation options
     * @return string|null Validated string or null if invalid
     */
    protected function validateString($value, array $options = [])
    {
        if (!is_string($value)) {
            $value = (string)$value;
        }
        
        // Check minimum length
        if (isset($options['min_length']) && mb_strlen($value) < $options['min_length']) {
            $this->addError('param', 'String is shorter than minimum length');
            return null;
        }
        
        // Check maximum length
        if (isset($options['max_length']) && mb_strlen($value) > $options['max_length']) {
            $this->addError('param', 'String exceeds maximum length');
            return null;
        }
        
        // Apply sanitization
        if (!empty($options['sanitize'])) {
            switch ($options['sanitize']) {
                case 'strip_tags':
                    $value = strip_tags($value);
                    break;
                
                case 'htmlspecialchars':
                    $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    break;
                
                case 'escape':
                    $value = addslashes($value);
                    break;
            }
        }
        
        // Check against pattern if provided
        if (isset($options['pattern']) && !preg_match($options['pattern'], $value)) {
            $this->addError('param', 'String does not match required pattern');
            return null;
        }
        
        return $value;
    }
    
    /**
     * Validate an email address
     *
     * @param mixed $value Value to validate
     * @return string|null Validated email or null if invalid
     */
    protected function validateEmail($value)
    {
        $email = filter_var($value, FILTER_VALIDATE_EMAIL);
        
        if ($email === false) {
            $this->addError('param', 'Invalid email address');
            return null;
        }
        
        return $email;
    }
    
    /**
     * Validate a URL
     *
     * @param mixed $value Value to validate
     * @return string|null Validated URL or null if invalid
     */
    protected function validateUrl($value)
    {
        $url = filter_var($value, FILTER_VALIDATE_URL);
        
        if ($url === false) {
            $this->addError('param', 'Invalid URL');
            return null;
        }
        
        return $url;
    }
    
    /**
     * Validate a boolean value
     *
     * @param mixed $value Value to validate
     * @return bool Validated boolean
     */
    protected function validateBool($value)
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Validate an IP address
     *
     * @param mixed $value Value to validate
     * @return string|null Validated IP or null if invalid
     */
    protected function validateIp($value)
    {
        $ip = filter_var($value, FILTER_VALIDATE_IP);
        
        if ($ip === false) {
            $this->addError('param', 'Invalid IP address');
            return null;
        }
        
        return $ip;
    }
    
    /**
     * Validate against a regex pattern
     *
     * @param mixed $value Value to validate
     * @param string $pattern Regex pattern to validate against
     * @return string|null Validated value or null if invalid
     */
    protected function validatePattern($value, string $pattern)
    {
        if (empty($pattern) || !preg_match($pattern, $value)) {
            $this->addError('param', 'Value does not match required pattern');
            return null;
        }
        
        return $value;
    }
    
    /**
     * Generate a new CSRF token
     *
     * @return string CSRF token
     */
    public function generateCsrfToken()
    {
        $token = bin2hex(random_bytes(32));
        
        // Store token in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION[$this->csrfTokenName] = $token;
        
        return $token;
    }
    
    /**
     * Validate a CSRF token
     *
     * @param string $token Token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateCsrfToken(string $token)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Check if token exists and matches
        if (empty($_SESSION[$this->csrfTokenName]) || $_SESSION[$this->csrfTokenName] !== $token) {
            $this->addError('csrf', 'Invalid or expired CSRF token');
            $this->logger->warning('Validation failure: Invalid CSRF token', [
                'provided_token' => $token,
                'expected_token' => $_SESSION[$this->csrfTokenName] ?? 'none'
            ]);
            return false;
        }
        
        // Token used, generate a new one for the next request
        $this->generateCsrfToken();
        
        return true;
    }
    
    /**
     * Add an error to the error collection
     *
     * @param string $field Field with error
     * @param string $message Error message
     * @return void
     */
    protected function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
    
    /**
     * Get all validation errors
     *
     * @return array Array of validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Check if validation has errors
     *
     * @return bool True if has errors, false otherwise
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    /**
     * Handle validation failure
     *
     * @param int $statusCode HTTP status code to return (default: 400)
     * @return void
     */
    public function handleValidationFailure(int $statusCode = 400): void
    {
        header('HTTP/1.1 ' . $statusCode . ' Bad Request');
        header('Content-Type: application/json');
        
        echo json_encode([
            'error' => 'Validation Error',
            'message' => 'The request could not be validated.',
            'details' => $this->errors,
            'status' => $statusCode
        ]);
        
        exit;
    }
}
