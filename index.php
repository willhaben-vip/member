<?php
/**
 * Unified Redirect Service for willhaben.vip
 *
 * This script handles redirects from willhaben.at URLs to willhaben.vip URLs
 * for both seller profiles and product pages. It combines the best features
 * of both index.php and index-rr.php files.
 *
 * @version 1.1.0
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Constants
define('LOG_FILE', __DIR__ . '/../logs/application/redirect.log');
define('BASE_URL', 'https://willhaben.vip');
define('SELLER_MAP', [
    '34434899' => 'rene.kapusta'
]);

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
 * Log message to file
 *
 * @param string $message Message to log
 * @return void
 */
function logMessage($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;

    // Ensure log directory exists
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Write to log file
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}

/**
 * Redirect to the specified URL
 *
 * @param string $url URL to redirect to
 * @param int $status HTTP status code for redirect
 * @return void
 * @throws RedirectException
 */
function redirectTo($url, $status = 301)
{
    // Log the redirect
    logMessage("Redirecting to: {$url}");

    // Throw RedirectException instead of directly sending headers
    throw new RedirectException($url, $status);
}

/**
 * Verify seller ID using JSON data
 *
 * @param string $sellerId The seller ID to verify
 * @return bool|string Returns slug if valid, false otherwise
 */
function verifySellerAndGetSlug($sellerId)
{
    // Sanitize seller ID (numeric only)
    $sellerId = preg_replace('/[^0-9]/', '', $sellerId);

    // Check if seller ID is in our map
    if (isset(SELLER_MAP[$sellerId])) {
        return SELLER_MAP[$sellerId];
    }

    // Look for seller JSON file in various directories
    foreach (scandir(__DIR__) as $item) {
        if (is_dir(__DIR__ . '/' . $item) && $item != '.' && $item != '..') {
            $jsonFile = __DIR__ . '/' . $item . '/' . $sellerId . '.json';
            if (file_exists($jsonFile)) {
                return $item; // Return the directory name as slug
            }
        }
    }

    return false;
}

/**
 * Serve a static file with proper MIME type
 *
 * @param string $filePath Path to the file
 * @return void
 */
function serveStaticFile($filePath)
{
    // Set appropriate content type
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'xml' => 'application/xml',
        'html' => 'text/html',
        'htm' => 'text/html',
        'pdf' => 'application/pdf',
        'svg' => 'image/svg+xml'
    ];

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $contentType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';

    header('Content-Type: ' . $contentType);
    readfile($filePath);
    exit;
}

try {
    // Get the request URI
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    // Log the incoming request
    logMessage("Received request: {$requestUri}");

    // Pattern matching for seller profile URLs
    if (preg_match('#^/iad/kaufen-und-verkaufen/verkaeuferprofil/([0-9]+)/?$#i', $requestUri, $matches)) {
        $sellerId = $matches[1];

        // Log seller ID found
        logMessage("Seller ID found: {$sellerId}");

        // Verify seller and get slug
        $sellerSlug = verifySellerAndGetSlug($sellerId);

        if ($sellerSlug) {
            // Redirect to seller profile page
            redirectTo(BASE_URL . '/' . $sellerSlug . '/');
        } else {
            logMessage("Invalid seller ID: {$sellerId}");
            // Redirect to homepage if seller not found
            redirectTo(BASE_URL);
        }
    }
    // Pattern matching for product URLs
    elseif (preg_match('#^/iad/kaufen-und-verkaufen/d/([\w-]+)-([0-9]+)/?$#i', $requestUri, $matches)) {
        $productSlug = $matches[1];
        $productId = $matches[2];

        // Log product info found
        logMessage("Product found - Slug: {$productSlug}, ID: {$productId}");

        // Use default seller for product pages
        $sellerSlug = 'rene.kapusta';

        // Redirect to product page
        redirectTo(BASE_URL . '/' . $sellerSlug . '/' . $productSlug . '-' . $productId);
    }
    // Handle direct file requests or fallback to homepage
    else {
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $filePath = __DIR__ . $requestPath;

        if (file_exists($filePath) && is_file($filePath)) {
            logMessage("Serving static file: {$filePath}");
            serveStaticFile($filePath);
        } else {
            // Default: redirect to homepage
            logMessage("No matching patterns, redirecting to homepage");
            redirectTo(BASE_URL);
        }
    }
} catch (RedirectException $e) {
    // Handle the redirect
    header("HTTP/1.1 {$e->getStatus()} Moved Permanently");
    header("Location: {$e->getUrl()}");
    exit;
} catch (\Throwable $e) {
    // Log error
    logMessage("Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());

    // Redirect to homepage in case of error
    try {
        redirectTo(BASE_URL);
    } catch (RedirectException $re) {
        header("HTTP/1.1 {$re->getStatus()} Moved Permanently");
        header("Location: {$re->getUrl()}");
        exit;
    }
}

