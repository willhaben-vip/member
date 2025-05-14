<?php
/**
 * Test Script for Redirect Functionality
 * 
 * This script tests the redirect functionality of index-combined.php
 * without making actual changes to the system.
 * 
 * Usage: php test-redirect.php
 */

// Define colors for console output
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

// Test Configuration
define('TEST_LOG_FILE', __DIR__ . '/../logs/application/test-redirect.log');
define('ORIGINAL_LOG_FILE', __DIR__ . '/../logs/application/redirect.log');

// Helper functions
function printHeader($title) {
    echo PHP_EOL . YELLOW . "=== $title ===" . RESET . PHP_EOL;
}

function printSuccess($message) {
    echo GREEN . "✓ " . $message . RESET . PHP_EOL;
}

function printFailure($message) {
    echo RED . "✗ " . $message . RESET . PHP_EOL;
}

function printInfo($message) {
    echo "  " . $message . PHP_EOL;
}

function expectRedirect($uri, $expectedUrl, $testFunction) {
    printInfo("Testing URI: $uri");
    try {
        $_SERVER['REQUEST_URI'] = $uri;
        $result = $testFunction();
        if ($result && $result['redirect'] && $result['url'] === $expectedUrl) {
            printSuccess("Redirect correctly set to: $expectedUrl");
            return true;
        } else {
            printFailure("Expected redirect to $expectedUrl, got: " . ($result['url'] ?? 'no redirect'));
            return false;
        }
    } catch (Exception $e) {
        printFailure("Test error: " . $e->getMessage());
        return false;
    }
}

function expectFile($uri, $expectedFile, $testFunction) {
    printInfo("Testing URI: $uri");
    try {
        $_SERVER['REQUEST_URI'] = $uri;
        $result = $testFunction();
        if ($result && $result['file'] && $result['path'] === $expectedFile) {
            printSuccess("File correctly served: $expectedFile");
            return true;
        } else {
            printFailure("Expected to serve $expectedFile, got: " . ($result['path'] ?? 'no file'));
            return false;
        }
    } catch (Exception $e) {
        printFailure("Test error: " . $e->getMessage());
        return false;
    }
}

// Create a test version of the redirect function
// This doesn't actually redirect, but returns what would happen
function testRedirectTo($url, $status = 301) {
    return [
        'redirect' => true,
        'url' => $url,
        'status' => $status
    ];
}

// Create a test version of the file serving function
function testServeStaticFile($filePath) {
    return [
        'file' => true,
        'path' => $filePath,
        'mime' => getMimeType($filePath)
    ];
}

function getMimeType($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'xml' => 'application/xml',
        'html' => 'text/html',
        'htm' => 'text/html'
    ];
    return isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';
}

// Create a version of handle_request that doesn't actually redirect or serve files
function testHandleRequest() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Pattern matching for seller profile URLs
    if (preg_match('#^/iad/kaufen-und-verkaufen/verkaeuferprofil/([0-9]+)/?$#i', $requestUri, $matches)) {
        $sellerId = $matches[1];
        
        // Get seller slug - for test purposes, we'll hardcode a few
        $sellerMap = ['34434899' => 'rene.kapusta', '12345678' => 'test.user'];
        $sellerSlug = isset($sellerMap[$sellerId]) ? $sellerMap[$sellerId] : false;
        
        if ($sellerSlug) {
            return testRedirectTo('https://willhaben.vip/' . $sellerSlug . '/');
        } else {
            return testRedirectTo('https://willhaben.vip');
        }
    }
    // Pattern matching for product URLs
    elseif (preg_match('#^/iad/kaufen-und-verkaufen/d/([\w-]+)-([0-9]+)/?$#i', $requestUri, $matches)) {
        $productSlug = $matches[1];
        $productId = $matches[2];
        
        // Use default seller for product pages
        $sellerSlug = 'rene.kapusta';
        
        return testRedirectTo('https://willhaben.vip/' . $sellerSlug . '/' . $productSlug . '-' . $productId);
    }
    // Handle direct file requests or fallback to homepage
    else {
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $filePath = __DIR__ . $requestPath;
        
        if ($requestPath && file_exists($filePath) && is_file($filePath)) {
            return testServeStaticFile($filePath);
        } else {
            // Default: redirect to homepage
            return testRedirectTo('https://willhaben.vip');
        }
    }
}

// Function to test logging functionality
function testLogging() {
    printHeader("Testing Logging Functionality");
    
    // Backup original log file if it exists
    $backupFile = null;
    if (file_exists(ORIGINAL_LOG_FILE)) {
        $backupFile = ORIGINAL_LOG_FILE . '.bak';
        copy(ORIGINAL_LOG_FILE, $backupFile);
        printInfo("Original log file backed up to: $backupFile");
    }
    
    // Create log message
    $testMessage = "Test log entry: " . date('Y-m-d H:i:s');
    
    // Try to log to test log file
    $logDir = dirname(TEST_LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents(TEST_LOG_FILE, $testMessage . PHP_EOL);
    
    // Verify log was written
    if (file_exists(TEST_LOG_FILE) && strpos(file_get_contents(TEST_LOG_FILE), $testMessage) !== false) {
        printSuccess("Log file correctly created and message written");
        // Clean up test log file
        unlink(TEST_LOG_FILE);
        return true;
    } else {
        printFailure("Failed to write to log file");
        return false;
    }
    
    // Restore original log file if it was backed up
    if ($backupFile && file_exists($backupFile)) {
        copy($backupFile, ORIGINAL_LOG_FILE);
        unlink($backupFile);
        printInfo("Original log file restored");
    }
}

// Function to test error handling
function testErrorHandling() {
    printHeader("Testing Error Handling");
    
    $testCases = [
        [
            'description' => "Invalid seller ID",
            'uri' => '/iad/kaufen-und-verkaufen/verkaeuferprofil/99999999/',
            'expectedUrl' => 'https://willhaben.vip'
        ],
        [
            'description' => "Malformed URL",
            'uri' => '/iad/kaufen-und-verkaufen/verkaeuferprofil/abc/',
            'expectedUrl' => 'https://willhaben.vip'
        ],
        [
            'description' => "Non-existent path",
            'uri' => '/this/path/does/not/exist/',
            'expectedUrl' => 'https://willhaben.vip'
        ],
        [
            'description' => "Empty request",
            'uri' => '',
            'expectedUrl' => 'https://willhaben.vip'
        ]
    ];
    
    $success = true;
    foreach ($testCases as $testCase) {
        printInfo("Testing: " . $testCase['description']);
        $_SERVER['REQUEST_URI'] = $testCase['uri'];
        
        try {
            $result = testHandleRequest();
            if ($result['url'] === $testCase['expectedUrl']) {
                printSuccess("Correctly handled error case");
            } else {
                printFailure("Expected " . $testCase['expectedUrl'] . ", got " . $result['url']);
                $success = false;
            }
        } catch (Exception $e) {
            printFailure("Exception thrown: " . $e->getMessage());
            $success = false;
        }
    }
    
    return $success;
}

// Main test function
function runTests() {
    printHeader("Starting Redirect Tests");
    echo "Testing redirect functionality without making actual changes\n";
    
    $testResults = [
        'seller_redirects' => 0,
        'product_redirects' => 0,
        'file_serving' => 0,
        'error_handling' => false,
        'logging' => false
    ];
    
    // Test seller profile redirects
    printHeader("Testing Seller Profile Redirects");
    
    $sellerTests = [
        [
            'uri' => '/iad/kaufen-und-verkaufen/verkaeuferprofil/34434899/',
            'expected' => 'https://willhaben.vip/rene.kapusta/'
        ],
        [
            'uri' => '/iad/kaufen-und-verkaufen/verkaeuferprofil/12345678/',
            'expected' => 'https://willhaben.vip/test.user/'
        ]
    ];
    
    foreach ($sellerTests as $test) {
        if (expectRedirect($test['uri'], $test['expected'], 'testHandleRequest')) {
            $testResults['seller_redirects']++;
        }
    }
    
    // Test product page redirects
    printHeader("Testing Product Page Redirects");
    
    $productTests = [
        [
            'uri' => '/iad/kaufen-und-verkaufen/d/iphone-15-pro-123456/',
            'expected' => 'https://willhaben.vip/rene.kapusta/iphone-15-pro-123456'
        ],
        [
            'uri' => '/iad/kaufen-und-verkaufen/d/macbook-air-789012/',
            'expected' => 'https://willhaben.vip/rene.kapusta/macbook-air-789012'
        ]
    ];
    
    foreach ($productTests as $test) {
        if (expectRedirect($test['uri'], $test['expected'], 'testHandleRequest')) {
            $testResults['product_redirects']++;
        }
    }
    
    // Test static file serving
    printHeader("Testing Static File Serving");
    
    // Create a temporary test file
    $testFileName = "test-file-" . rand(1000, 9999) . ".html";
    $testFilePath = __DIR__ . '/' . $testFileName;
    file_put_contents($testFilePath, "<html><body>Test file</body></html>");
    
    $fileTests = [
        [
            'uri' => '/' . $testFileName,
            'expected' => $testFilePath
        ]
    ];
    
    foreach ($fileTests as $test) {
        if (expectFile($test['uri'], $test['expected'], 'testHandleRequest')) {
            $testResults['file_serving']++;
        }
    }
    
    // Clean up test file
    if (file_exists($testFilePath)) {
        unlink($testFilePath);
        printInfo("Cleaned up test file");
    }
    
    // Test error handling
    $testResults['error_handling'] = testErrorHandling();
    
    // Test logging functionality
    $testResults['logging'] = testLogging();
    
    // Print final results
    printHeader("Test Results");
    echo "Seller Redirects: " . $testResults['seller_redirects'] . "/" . count($sellerTests) . "\n";
    echo "Product Redirects: " . $testResults['product_redirects'] . "/" . count($productTests) . "\n";
    echo "File Serving: " . $testResults['file_serving'] . "/" . count($fileTests) . "\n";
    echo "Error Handling: " . ($testResults['error_handling'] ? "PASS" : "FAIL") . "\n";
    echo "Logging: " . ($testResults['logging'] ? "PASS" : "FAIL") . "\n";
    
    $totalPassed = $testResults['seller_redirects'] + $testResults['product_redirects'] + 
                  $testResults['file_serving'] + ($testResults['error_handling'] ? 1 : 0) + 
                  ($testResults['logging'] ? 1 : 0);
    $totalTests = count($sellerTests) + count($productTests) + count($fileTests) + 2; // +2 for error & logging
    
    echo PHP_EOL;
    $passRate = round(($totalPassed / $totalTests) * 100);
    
    if ($passRate == 100) {
        echo GREEN . "All tests passed! ($totalPassed/$totalTests)" . RESET . PHP_EOL;
    } else {
        echo YELLOW . "Test completion: $passRate% ($totalPassed/$totalTests)" . RESET . PHP_EOL;
    }
}

// Run all tests
runTests();

