<?php
/**
 * Redirect Service for willhaben.vip to willhaben.at
 *
 * This script handles redirects from willhaben.at URLs to willhaben.vip URLs
 * for both seller profiles and product pages.
 *
 * @version 1.0.0
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Constants
define('LOG_FILE', __DIR__ . '/redirect_log.txt');
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

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;

    // Write to log file
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}

function redirectTo($url) {
    // Log the redirect
    logMessage("Redirecting to: {$url}");

    // Instead of using header/exit, throw an exception
    throw new RedirectException($url, 301);
}

function verifySellerAndGetSlug($sellerId) {
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
                return $item;
            }
        }
    }

    return false;
}

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
} elseif (preg_match('#^/iad/kaufen-und-verkaufen/d/([\w-]+)-([0-9]+)/?$#i', $requestUri, $matches)) {
    $productSlug = $matches[1];
    $productId = $matches[2];

    // Log product info found
    logMessage("Product found - Slug: {$productSlug}, ID: {$productId}");

    // We'll use rene.kapusta as the default seller for simplicity
    $sellerSlug = 'rene.kapusta';

    // Redirect to product page
    redirectTo(BASE_URL . '/' . $sellerSlug . '/' . $productSlug . '-' . $productId);
} else {
    // Default: redirect to homepage
    logMessage("No matching patterns, redirecting to homepage");
    redirectTo(BASE_URL);
}

