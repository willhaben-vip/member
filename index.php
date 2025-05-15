<?php
/**
 * Unified Redirect Service for willhaben.vip
 *
 * This script handles redirects from willhaben.at URLs to willhaben.vip URLs
 * for both seller profiles and product pages.
 *
 * @version 1.2.0
 */

// Initialize application and load dependencies
$bootstrap = require_once __DIR__ . '/bootstrap.php';
$logger = $GLOBALS['logger'];
$rateLimiter = $GLOBALS['rateLimiter'];
$requestValidator = $GLOBALS['requestValidator'];
$cacheManager = $GLOBALS['cacheManager'];
$securityHeadersMiddleware = $GLOBALS['securityHeadersMiddleware'];
$staticAssetsConfig = $bootstrap['staticAssetsConfig'];
$metricsCollector = $GLOBALS['metricsCollector'] ?? null;

// Define metrics collection function that safely collects metrics if available
function collectMetrics($action, $params = []) {
    global $metricsCollector;
    if ($metricsCollector === null) return;
    
    try {
        switch ($action) {
            case 'cache_hit':
                $metricsCollector->recordCacheOperation('get', 'hit');
                break;
            case 'cache_miss':
                $metricsCollector->recordCacheOperation('get', 'miss');
                break;
            case 'cache_set':
                $metricsCollector->recordCacheOperation('set', 'success');
                break;
            case 'redirect':
                $metricsCollector->recordRedirect(
                    $params['type'] ?? 'unknown', 
                    $params['status'] ?? 301
                );
                break;
            case 'error':
                $metricsCollector->recordError(
                    $params['type'] ?? 'unknown', 
                    $params['code'] ?? 500
                );
                break;
            case 'rate_limit':
                $metricsCollector->recordRateLimitHit($params['route'] ?? 'unknown');
                break;
            case 'static_file':
                $metricsCollector->recordStaticFileServed(
                    $params['type'] ?? 'unknown',
                    $params['compressed'] ?? false
                );
                break;
            case 'request':
                $metricsCollector->recordRequestMetrics(
                    $params['status'] ?? 200,
                    $params['route'] ?? 'unknown',
                    $params['method'] ?? 'GET',
                    $params['size'] ?? 0
                );
                break;
        }
    } catch (\Throwable $e) {
        // Silent failure for metrics to not impact main application
    }
}

// Custom RedirectException
class RedirectException extends Exception {
    private $url;
    private $status;

    public function __construct($url, $status = 302) {
        $this->url = $url;
        $this->status = $status;
        parent::__construct("Redirect to: " . $url);
    }

    public function getUrl() {
        return $this->url;
    }

    public function getStatus() {
        return $this->status;
    }
}
/**
 * Redirect to the specified URL
 *
 * @param string $url URL to redirect to
 * @param int $status HTTP status code for redirect
 * @param string $reason Optional reason for the redirect
 * @return void
 * @throws RedirectException
 */
function redirectTo($url, $status = 301, $reason = '')
{
    global $logger;
    
    // Log the redirect with context
    $context = [
        'url' => $url,
        'status' => $status,
        'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none'
    ];
    
    if ($reason) {
        $context['reason'] = $reason;
    }
    
    $logger->info("Redirecting to: {$url}", $context);

    // Throw RedirectException instead of directly sending headers
    throw new RedirectException($url, $status);
}

/**
 * Verify seller ID using JSON data with caching for performance
 *
 * @param string $sellerId The seller ID to verify
 * @return bool|string Returns slug if valid, false otherwise
 */
function verifySellerAndGetSlug($sellerId)
{
    global $cacheManager, $logger;
    
    // Start timing the seller verification
    $startTime = microtime(true);
    
    // Sanitize seller ID (numeric only)
    $sellerId = preg_replace('/[^0-9]/', '', $sellerId);
    
    // Try to get from cache first
    $cachedSlug = $cacheManager->getSellerSlug($sellerId);
    
    // If found in cache, return it
    if ($cachedSlug !== null) {
        $logger->debug("Seller ID found in cache", ['seller_id' => $sellerId, 'slug' => $cachedSlug]);
        // Record cache hit in metrics
        collectMetrics('cache_hit');
        return $cachedSlug;
    }
    
    // Record cache miss in metrics
    collectMetrics('cache_miss');
    
    $logger->debug("Seller ID not in cache, checking mapping", ['seller_id' => $sellerId]);
    
    try {
        // Check if seller ID is in our map
        if (isset(SELLER_MAP[$sellerId])) {
            $slug = SELLER_MAP[$sellerId];
            
            // Cache the result for future lookups
            $cacheManager->cacheSellerLookup($sellerId, $slug, $GLOBALS['cacheConfig']['ttl']['seller']);
            
            // Record cache set in metrics
            collectMetrics('cache_set');
            
            $logger->debug("Seller ID found in map", ['seller_id' => $sellerId, 'slug' => $slug]);
            return $slug;
        }
        
        $logger->debug("Seller ID not in map, checking on filesystem", ['seller_id' => $sellerId]);
        
        // Optimize file system operations - use glob instead of scandir to find potential directories faster
        $dirs = glob(__DIR__ . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            
            // Skip dot directories
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }
            
            // Check if the JSON file exists directly instead of scanning all files
            $jsonFile = $dir . '/' . $sellerId . '.json';
            
            if (file_exists($jsonFile)) {
                // Cache the result for future lookups
                $cacheManager->cacheSellerLookup($sellerId, $dirName, $GLOBALS['cacheConfig']['ttl']['seller']);
                
                // Record cache set in metrics
                collectMetrics('cache_set');
                
                $logger->debug("Seller JSON file found", [
                    'seller_id' => $sellerId, 
                    'slug' => $dirName,
                    'path' => $jsonFile
                ]);
                
                return $dirName; // Return the directory name as slug
            }
        }
        
        // Seller not found - cache the negative result with a shorter TTL
        $cacheManager->cacheSellerLookup($sellerId, false, $GLOBALS['cacheConfig']['ttl']['temp']);
        
        // Record cache set in metrics
        collectMetrics('cache_set');
        
        $logger->debug("Seller ID not found", ['seller_id' => $sellerId]);
        return false;
    } catch (\Throwable $e) {
        $logger->error("Error in seller verification", [
            'seller_id' => $sellerId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        // In case of error, return false but don't cache the result
        return false;
    }
}

/**
 * Serve a static file with proper caching, ETags, and security headers
 *
 * @param string $filePath Path to the file
 * @return void
 */
function serveStaticFile($filePath)
{
    global $logger, $staticAssetsConfig, $securityHeadersMiddleware, $metricsCollector;
    
    try {
        // Get file information
        $fileSize = filesize($filePath);
        $lastModified = filemtime($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Set appropriate content type with comprehensive MIME types
        $mimeTypes = [
            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            
            // Text and code
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'htm' => 'text/html',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            'xml' => 'application/xml',
            'json' => 'application/json',
            
            // Documents
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            
            // Fonts
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            
            // Archives
            'zip' => 'application/zip',
            'gz' => 'application/gzip',
            'tar' => 'application/x-tar',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
        ];
        
        $contentType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';
        
        // 1. Generate ETag (based on file path, size, and modification time)
        $etagSource = $filePath . $fileSize . $lastModified;
        $etagStrength = ($staticAssetsConfig['etag']['strength'] === 'weak') ? 'W/' : '';
        $etag = $etagStrength . '"' . md5($etagSource) . '"';
        
        // 2. Set Last-Modified header
        $lastModifiedDate = gmdate('D, d M Y H:i:s', $lastModified) . ' GMT';
        
        // 3. Handle conditional requests (If-None-Match, If-Modified-Since)
        $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : null;
        $clientLastModified = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? trim($_SERVER['HTTP_IF_MODIFIED_SINCE']) : null;
        
        // Check if client's cached version is still valid
        if (($clientEtag !== null && $clientEtag === $etag) || 
            ($clientLastModified !== null && $clientLastModified === $lastModifiedDate)) {
            
            // Apply security headers for static content
            $securityHeadersMiddleware->applyForContext('static');
            
            // Client's cached version is still valid, send 304 Not Modified
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModifiedDate);
            
            // Log cache hit
            $logger->info('Static file cache hit (304)', [
                'path' => $filePath,
                'etag' => $etag
            ]);
            
            exit;
        }
        
        // 4. Set Cache-Control headers based on file type
        $cacheControl = $staticAssetsConfig['cache_control']['default'];
        if (isset($staticAssetsConfig['cache_control']['extensions'][$extension])) {
            $cacheControl = $staticAssetsConfig['cache_control']['extensions'][$extension];
        }
        
        // 5. Determine if we should compress the content
        $compress = false;
        if ($staticAssetsConfig['compression']['enabled'] && 
            in_array($contentType, $staticAssetsConfig['compression']['types']) && 
            extension_loaded('zlib') && 
            filesize($filePath) > 1024) { // Only compress files larger than 1KB
            
            $acceptEncoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
            if (strpos($acceptEncoding, 'gzip') !== false) {
                $compress = true;
            }
        }
        
        // 6. Apply security headers for static content
        $securityHeadersMiddleware->applyForContext('static');
        
        // 7. Set response headers
        header('Content-Type: ' . $contentType);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModifiedDate);
        header('Cache-Control: ' . $cacheControl);
        header('X-Content-Type-Options: nosniff'); // Security: prevent MIME-type sniffing
        
        // For versioned assets, encourage longer caching
        if ($staticAssetsConfig['versioning']['enabled'] && 
            isset($_GET[$staticAssetsConfig['versioning']['parameter']])) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT'); // 1 year
        }
        
        // 8. Handle compression if enabled
        if ($compress) {
            header('Content-Encoding: gzip');
            
            // Set correct content length if possible
            $level = $staticAssetsConfig['compression']['level'] ?? 6;
            $compressedContent = gzencode(file_get_contents($filePath), $level);
            header('Content-Length: ' . strlen($compressedContent));
            
            echo $compressedContent;
            
            // Record metrics for compressed file
            collectMetrics('static_file', [
                'type' => $extension,
                'compressed' => true
            ]);
            
            // Record response size
            collectMetrics('request', [
                'route' => 'static_file',
                'status' => 200,
                'size' => strlen($compressedContent)
            ]);
        } else {
            // Set content length for uncompressed content
            header('Content-Length: ' . $fileSize);
            
            // Output file content
            readfile($filePath);
            
            // Record metrics for uncompressed file
            collectMetrics('static_file', [
                'type' => $extension,
                'compressed' => false
            ]);
            
            // Record response size
            collectMetrics('request', [
                'route' => 'static_file',
                'status' => 200,
                'size' => $fileSize
            ]);
        }
        
        // Log successful file serving
        $logger->info('Served static file', [
            'path' => $filePath,
            'type' => $contentType,
            'size' => $fileSize,
            'compressed' => $compress,
            'cache_control' => $cacheControl
        ]);
        
        exit;
    } catch (\Throwable $e) {
            // Log error
            $logger->error('Failed to serve static file', [
                'path' => $filePath,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Record error in metrics
            collectMetrics('error', [
                'type' => 'static_file',
                'code' => 500
            ]);
        
        // Return a generic error
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain');
        echo 'Error serving file';
        exit;
    }
}

try {
    // Get the request URI
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    
    // Check if this is a metrics endpoint request
    if ($requestUri === ($metricsConfig['endpoint']['path'] ?? '/metrics')) {
        // Handle metrics endpoint request
        try {
            // Verify authentication if enabled
            if (($metricsConfig['endpoint']['auth']['enabled'] ?? true)) {
                $authenticated = false;
                
                // Check IP whitelist
                $ipWhitelist = $metricsConfig['endpoint']['auth']['ip_whitelist'] ?? [];
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                
                foreach ($ipWhitelist as $allowedIp) {
                    // Simple exact match
                    if ($clientIp === $allowedIp) {
                        $authenticated = true;
                        break;
                    }
                    
                    // CIDR match
                    if (strpos($allowedIp, '/') !== false) {
                        list($subnet, $bits) = explode('/', $allowedIp);
                        
                        // Convert IP and subnet to long
                        $ipLong = ip2long($clientIp);
                        $subnetLong = ip2long($subnet);
                        
                        if ($ipLong !== false && $subnetLong !== false) {
                            // Create mask based on bits
                            $mask = -1 << (32 - (int)$bits);
                            
                            // Compare masked IP and subnet
                            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                                $authenticated = true;
                                break;
                            }
                        }
                    }
                }
                
                // If not in IP whitelist, check basic auth
                if (!$authenticated) {
                    $username = $metricsConfig['endpoint']['auth']['username'] ?? '';
                    $password = $metricsConfig['endpoint']['auth']['password'] ?? '';
                    
                    // Check HTTP basic auth
                    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']) &&
                        $_SERVER['PHP_AUTH_USER'] === $username && $_SERVER['PHP_AUTH_PW'] === $password) {
                        $authenticated = true;
                    }
                }
                
                // Handle authentication failure
                if (!$authenticated) {
                    $logger->warning('Unauthorized metrics access attempt', [
                        'ip' => $clientIp,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ]);
                    
                    // Record error in metrics
                    collectMetrics('error', [
                        'type' => 'auth',
                        'code' => 401
                    ]);
                    
                    // Return 401 Unauthorized
                    header('HTTP/1.1 401 Unauthorized');
                    header('WWW-Authenticate: Basic realm="Metrics"');
                    header('Content-Type: text/plain');
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                    echo "# Authentication required for metrics access\n";
                    exit;
                }
            }
            
            // Check for cached metrics response
            $cacheKey = 'metrics:endpoint:response';
            $cacheTtl = $metricsConfig['endpoint']['cache_ttl'] ?? 10;
            $cachedResponse = $cacheManager->get($cacheKey);
            
            if ($cachedResponse !== null) {
                // Use cached response
                header('Content-Type: text/plain; version=0.0.4');
                header('Cache-Control: public, max-age=' . $cacheTtl);
                echo $cachedResponse;
                
                $logger->debug('Served cached metrics response');
                exit;
            }
            
            // Set timeout for metrics generation
            $timeout = $metricsConfig['endpoint']['timeout'] ?? 5;
            set_time_limit($timeout);
            
            // Generate metrics in Prometheus format
            $metrics = $metricsCollector->getPrometheusMetrics();
            
            // Cache the response
            $cacheManager->set($cacheKey, $metrics, $cacheTtl);
            
            // Send response
            header('Content-Type: text/plain; version=0.0.4');
            header('Cache-Control: public, max-age=' . $cacheTtl);
            echo $metrics;
            
            $logger->info('Served metrics endpoint', [
                'size' => strlen($metrics),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            exit;
        } catch (\Throwable $e) {
            $logger->error('Error serving metrics endpoint', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Record error in metrics
            collectMetrics('error', [
                'type' => 'metrics',
                'code' => 500
            ]);
            
            // Return 500 Internal Server Error
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/plain');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo "# Error generating metrics: {$e->getMessage()}\n";
            exit;
        }
    }
    
    // Create context for request logging
    $requestContext = [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    // Log the incoming request
    $logger->info("Received request: {$requestUri}", $requestContext);
    
    // Validate request URI
    $validatedUri = $requestValidator->validateUri($requestUri);
    if ($validatedUri === false) {
        $logger->warning('Invalid request URI', [
            'uri' => $requestUri,
            'errors' => $requestValidator->getErrors()
        ]);
        $requestValidator->handleValidationFailure(400);
    }
    
    // Validate request headers
    $headers = getallheaders() ?: [];
    if (!$requestValidator->validateHeaders($headers)) {
        $logger->warning('Invalid request headers', [
            'errors' => $requestValidator->getErrors()
        ]);
        $requestValidator->handleValidationFailure(400);
    }
    
    // Determine the route type for rate limiting
    $routeType = 'default';
    $routeIdentifier = null;
    
    // Pattern matching for seller profile URLs
    if (preg_match('#^/iad/kaufen-und-verkaufen/verkaeuferprofil/([0-9]+)/?$#i', $requestUri, $matches)) {
        $sellerId = $matches[1];
        $routeType = 'seller_profile';
        $routeIdentifier = 'seller:' . $sellerId;
        
        // Check rate limit for seller profile requests
        if ($rateLimiter->isLimitExceeded($routeType)) {
            $logger->warning('Rate limit exceeded for seller profile request', [
                'seller_id' => $sellerId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Record rate limit in metrics
            collectMetrics('rate_limit', ['route' => $routeType]);
            
            $rateLimiter->handleLimitExceeded($routeType);
        }
        
        // Add rate limit headers
        $rateLimiter->setRateLimitHeaders($routeType);

        // Log seller ID found with context
        $logger->debug("Seller ID found: {$sellerId}", ['seller_id' => $sellerId]);

        // Verify seller and get slug
        $sellerSlug = verifySellerAndGetSlug($sellerId);

        if ($sellerSlug) {
            // Redirect to seller profile page
            // Record redirect in metrics before redirecting
            collectMetrics('redirect', ['type' => 'seller', 'status' => 301]);
            
            redirectTo(BASE_URL . '/' . $sellerSlug . '/', 301, 'Valid seller ID');
        } else {
            $logger->warning("Invalid seller ID: {$sellerId}", ['seller_id' => $sellerId]);
            // Record redirect in metrics before redirecting
            collectMetrics('redirect', ['type' => 'invalid_seller', 'status' => 301]);
            
            // Redirect to homepage if seller not found
            redirectTo(BASE_URL, 301, 'Invalid seller ID');
        }
    }
    // Pattern matching for product URLs
    elseif (preg_match('#^/iad/kaufen-und-verkaufen/d/([\w-]+)-([0-9]+)/?$#i', $requestUri, $matches)) {
        $productSlug = $matches[1];
        $productId = $matches[2];
        $routeType = 'product';
        $routeIdentifier = 'product:' . $productId;
        
        // Check rate limit for product requests
        if ($rateLimiter->isLimitExceeded($routeType)) {
            $logger->warning('Rate limit exceeded for product request', [
                'product_id' => $productId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Record rate limit in metrics
            collectMetrics('rate_limit', ['route' => $routeType]);
            
            $rateLimiter->handleLimitExceeded($routeType);
        }
        
        // Add rate limit headers
        $rateLimiter->setRateLimitHeaders($routeType);

        // Log product info found with context
        $logger->debug("Product found", [
            'slug' => $productSlug,
            'id' => $productId
        ]);

        // Use default seller for product pages
        $sellerSlug = 'rene.kapusta';

        // Record redirect in metrics before redirecting
        collectMetrics('redirect', ['type' => 'product', 'status' => 301]);
        
        // Redirect to product page
        redirectTo(BASE_URL . '/' . $sellerSlug . '/' . $productSlug . '-' . $productId, 301, 'Product redirect');
    }
    // Handle direct file requests
    else {
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $filePath = __DIR__ . $requestPath;
        
        if (file_exists($filePath) && is_file($filePath)) {
            $routeType = 'static';
            
            // Check rate limit for static file requests
            if ($rateLimiter->isLimitExceeded($routeType)) {
                $logger->warning('Rate limit exceeded for static file request', [
                    'path' => $requestPath,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                $rateLimiter->handleLimitExceeded($routeType);
            }
            
            // Add rate limit headers
            $rateLimiter->setRateLimitHeaders($routeType);
            
            $logger->info("Serving static file", ['path' => $filePath]);
            serveStaticFile($filePath);
        } else {
            // Default rule for unknown requests
            if ($rateLimiter->isLimitExceeded('default')) {
                $logger->warning('Rate limit exceeded for default request', [
                    'uri' => $requestUri,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                $rateLimiter->handleLimitExceeded('default');
            }
            
            // Add rate limit headers
            $rateLimiter->setRateLimitHeaders('default');
            
            // Default: redirect to homepage
            $logger->notice("No matching patterns, redirecting to homepage", ['uri' => $requestUri]);
            redirectTo(BASE_URL, 301, 'No matching patterns');
        }
    }
} catch (RedirectException $e) {
    // Apply security headers for redirects
    $securityHeadersMiddleware->applyForContext('redirect');
    
    // Add request ID to response headers for tracking
    header("X-Request-ID: " . $logger->getRequestId());
    
    // Handle the redirect
    header("HTTP/1.1 {$e->getStatus()} Moved Permanently");
    header("Location: {$e->getUrl()}");
    header("Cache-Control: max-age=86400, public"); // 1 day cache for redirects
    
    // Record final metrics before exit
    collectMetrics('redirect', [
        'type' => 'final',
        'status' => $e->getStatus()
    ]);
    
    // Record overall request metrics
    collectMetrics('request', [
        'route' => 'redirect',
        'status' => $e->getStatus(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ]);
    
    exit;
} catch (\Throwable $e) {
    // Log error with detailed context
    $logger->error("Application error: " . $e->getMessage(), [
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Record error in metrics
    collectMetrics('error', [
        'type' => 'application',
        'code' => 500
    ]);

    // Redirect to homepage in case of error
    try {
        redirectTo(BASE_URL, 302, 'Error recovery');
    } catch (RedirectException $re) {
        // Apply security headers for redirects
        $securityHeadersMiddleware->applyForContext('redirect');
        
        // Add request ID to response headers for tracking
        header("X-Request-ID: " . $logger->getRequestId());
        
        header("HTTP/1.1 {$re->getStatus()} Moved Permanently");
        header("Location: {$re->getUrl()}");
        header("Cache-Control: no-store, no-cache, must-revalidate"); // Don't cache error redirects
        
        // Record final metrics before exit
        collectMetrics('redirect', [
            'type' => 'error_recovery',
            'status' => $re->getStatus()
        ]);
        
        // Record overall request metrics
        collectMetrics('request', [
            'route' => 'error_redirect',
            'status' => $re->getStatus(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ]);
        
        exit;
    }
}

