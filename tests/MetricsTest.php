<?php
/**
 * Metrics Test Suite
 * 
 * Tests for the metrics collection system including performance benchmarks
 * and stress tests for high-traffic scenarios.
 */

// Define base path
define('APP_ROOT', dirname(__DIR__));

// Load bootstrap
require_once APP_ROOT . '/bootstrap.php';

/**
 * Simple testing framework
 */
class TestRunner {
    private $tests = [];
    private $results = [
        'passed' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    /**
     * Add a test
     *
     * @param string $name Test name
     * @param callable $callback Test callback
     * @return void
     */
    public function addTest(string $name, callable $callback): void {
        $this->tests[$name] = $callback;
    }
    
    /**
     * Run all tests
     *
     * @return array Test results
     */
    public function run(): array {
        echo "Running tests...\n";
        
        foreach ($this->tests as $name => $callback) {
            echo "- Running test: $name... ";
            
            try {
                $result = $callback();
                if ($result === true) {
                    echo "PASSED\n";
                    $this->results['passed']++;
                } else {
                    echo "FAILED\n";
                    $this->results['failed']++;
                    $this->results['errors'][] = [
                        'test' => $name,
                        'message' => is_string($result) ? $result : 'Test failed'
                    ];
                }
            } catch (\Throwable $e) {
                echo "ERROR: {$e->getMessage()}\n";
                $this->results['failed']++;
                $this->results['errors'][] = [
                    'test' => $name,
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
            }
        }
        
        echo "\nTest Results:\n";
        echo "- Passed: {$this->results['passed']}\n";
        echo "- Failed: {$this->results['failed']}\n";
        
        if (!empty($this->results['errors'])) {
            echo "\nErrors:\n";
            foreach ($this->results['errors'] as $error) {
                echo "- {$error['test']}: {$error['message']}\n";
            }
        }
        
        return $this->results;
    }
    
    /**
     * Assert that a condition is true
     *
     * @param mixed $condition Condition to test
     * @param string $message Error message on failure
     * @return bool|string True if passing, error message if failing
     */
    public function assert($condition, string $message = 'Assertion failed') {
        if ($condition) {
            return true;
        }
        return $message;
    }
    
    /**
     * Assert that two values are equal
     *
     * @param mixed $expected Expected value
     * @param mixed $actual Actual value
     * @param string $message Error message on failure
     * @return bool|string True if passing, error message if failing
     */
    public function assertEquals($expected, $actual, string $message = 'Values not equal') {
        if ($expected === $actual) {
            return true;
        }
        return $message . " (Expected: " . var_export($expected, true) . ", Actual: " . var_export($actual, true) . ")";
    }
    
    /**
     * Assert that a value contains a substring
     *
     * @param string $needle Substring to find
     * @param string $haystack String to search in
     * @param string $message Error message on failure
     * @return bool|string True if passing, error message if failing
     */
    public function assertContains(string $needle, string $haystack, string $message = 'String does not contain substring') {
        if (strpos($haystack, $needle) !== false) {
            return true;
        }
        return $message . " (Looking for: " . var_export($needle, true) . ")";
    }
    
    /**
     * Run a performance benchmark
     *
     * @param callable $callback Function to benchmark
     * @param int $iterations Number of iterations
     * @return array Performance results
     */
    public function benchmark(callable $callback, int $iterations = 1000): array {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $callback();
            $end = microtime(true);
            $times[] = $end - $start;
        }
        
        return [
            'min' => min($times),
            'max' => max($times),
            'avg' => array_sum($times) / count($times),
            'median' => $this->median($times),
            'p95' => $this->percentile($times, 95),
            'p99' => $this->percentile($times, 99),
            'iterations' => $iterations,
            'total_time' => array_sum($times)
        ];
    }
    
    /**
     * Calculate the median value of an array
     *
     * @param array $array Array of values
     * @return float Median value
     */
    private function median(array $array): float {
        sort($array);
        $count = count($array);
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($array[$middle - 1] + $array[$middle]) / 2;
        }
        
        return $array[$middle];
    }
    
    /**
     * Calculate a percentile value of an array
     *
     * @param array $array Array of values
     * @param int $percentile Percentile to calculate (0-100)
     * @return float Percentile value
     */
    private function percentile(array $array, int $percentile): float {
        sort($array);
        $count = count($array);
        $index = ceil($percentile / 100 * $count) - 1;
        return $array[$index];
    }
}

// Create test runner
$runner = new TestRunner();

// Mock dependencies
class MockLogger {
    public function debug($message, $context = []) {}
    public function info($message, $context = []) {}
    public function warning($message, $context = []) {}
    public function error($message, $context = []) {}
    public function getRequestId() { return 'test-request-id'; }
}

class MockCache {
    private $data = [];
    
    public function get($key) {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
    
    public function set($key, $value, $ttl = null) {
        $this->data[$key] = $value;
        return true;
    }
    
    public function delete($key) {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            return true;
        }
        return false;
    }
    
    public function clear() {
        $this->data = [];
        return true;
    }
}

// Test 1: Basic metrics collection functionality
$runner->addTest('basic_metrics_collection', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector with test config
    $config = [
        'enabled' => true,
        'retention' => [
            'counters' => 3600,
            'gauges' => 1800,
            'histograms' => 3600
        ]
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Test counter increment
    $collector->incrementCounter('requests_total', [
        'method' => 'GET',
        'route' => 'test',
        'status' => '200'
    ]);
    
    // Generate Prometheus output
    $output = $collector->getPrometheusMetrics();
    
    // Check that output contains the counter
    $containsCounter = $runner->assertContains('requests_total{method=GET,route=test,status=200} 1', $output);
    if ($containsCounter !== true) {
        return $containsCounter;
    }
    
    // Test gauge setting
    $collector->setGauge('memory_usage_bytes', 12345678);
    
    // Generate Prometheus output again
    $output = $collector->getPrometheusMetrics();
    
    // Check that output contains the gauge
    $containsGauge = $runner->assertContains('memory_usage_bytes 12345678', $output);
    if ($containsGauge !== true) {
        return $containsGauge;
    }
    
    // Test histogram
    $collector->observeHistogram('request_duration_seconds', 0.123, [
        'route' => 'test'
    ]);
    
    // Generate Prometheus output again
    $output = $collector->getPrometheusMetrics();
    
    // Check that output contains histogram data
    $containsHistogram = $runner->assertContains('request_duration_seconds_sum{route=test} 0.123', $output);
    if ($containsHistogram !== true) {
        return $containsHistogram;
    }
    
    return true;
});

// Test 2: Counter increments correctly
$runner->addTest('counter_increments', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector with test config
    $config = [
        'enabled' => true
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Increment counter multiple times
    $collector->incrementCounter('test_counter', ['label' => 'value'], 1);
    $collector->incrementCounter('test_counter', ['label' => 'value'], 2);
    $collector->incrementCounter('test_counter', ['label' => 'value'], 3);
    
    // Generate Prometheus output
    $output = $collector->getPrometheusMetrics();
    
    // Check that counter has correct value (1+2+3 = 6)
    return $runner->assertContains('test_counter{label=value} 6', $output);
});

// Test 3: Metrics cache persistence
$runner->addTest('metrics_cache_persistence', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create config
    $config = [
        'enabled' => true,
        'retention' => [
            'counters' => 3600,
            'gauges' => 1800,
            'histograms' => 3600
        ]
    ];
    
    // Create first metrics collector and increment counter
    $collector1 = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    $collector1->incrementCounter('persistence_test', ['test' => 'value'], 5);
    
    // Persist metrics to cache
    $collector1->persistMetrics();
    
    // Create second metrics collector (simulating a new request)
    $collector2 = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Second collector should have loaded the metrics from cache
    $output = $collector2->getPrometheusMetrics();
    
    // Check that counter value was preserved
    return $runner->assertContains('persistence_test{test=value} 5', $output);
});

// Test 4: Prometheus format output
$runner->addTest('prometheus_format', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector
    $config = [
        'enabled' => true
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Add some metrics
    $collector->incrementCounter('http_requests_total', [
        'method' => 'GET',
        'endpoint' => '/metrics',
        'status' => '200'
    ]);
    
    $collector->observeHistogram('request_duration_seconds', 0.157, [
        'endpoint' => '/metrics'
    ]);
    
    // Generate Prometheus output
    $output = $collector->getPrometheusMetrics();
    
    // Check for proper Prometheus format elements
    $hasHelp = $runner->assertContains('# HELP http_requests_total', $output);
    if ($hasHelp !== true) return $hasHelp;
    
    $hasType = $runner->assertContains('# TYPE http_requests_total counter', $output);
    if ($hasType !== true) return $hasType;
    
    $hasHistogramType = $runner->assertContains('# TYPE request_duration_seconds histogram', $output);
    if ($hasHistogramType !== true) return $hasHistogramType;
    
    $hasBucket = $runner->assertContains('request_duration_seconds_bucket{endpoint=/metrics,le="', $output);
    if ($hasBucket !== true) return $hasBucket;
    
    return true;
});

// Test 5: Performance impact
$runner->addTest('performance_impact', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector
    $config = [
        'enabled' => true
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Benchmark without metrics collection
    $withoutMetrics = $runner->benchmark(function() {
        // Simulate application work
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data[$i] = md5(random_bytes(10));
        }
    }, 100);
    
    // Benchmark with metrics collection
    $withMetrics = $runner->benchmark(function() use ($collector) {
        // Simulate application work with metrics
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data[$i] = md5(random_bytes(10));
        }
        
        // Collect metrics
        $collector->incrementCounter('test_counter', ['iteration' => 'benchmark']);
        $collector->observeHistogram('benchmark_duration', 0.0123, ['test' => 'performance']);
    }, 100);
    
    // Calculate overhead
    $overhead = ($withMetrics['avg'] / $withoutMetrics['avg'] - 1) * 100;
    
    echo "  Performance overhead: " . number_format($overhead, 2) . "%\n";
    echo "  Without metrics: " . number_format($withoutMetrics['avg'] * 1000, 3) . "ms avg\n";
    echo "  With metrics: " . number_format($withMetrics['avg'] * 1000, 3) . "ms avg\n";
    
    // Ensure overhead is less than 10%
    return $runner->assert($overhead < 10, "Metrics collection overhead too high: {$overhead}%");
});

// Test 6: Stress test for high-traffic scenarios
$runner->addTest('high_traffic_stress_test', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector with test config
    $config = [
        'enabled' => true,
        'sampling' => [
            'rate' => 0.1  // 10% sampling rate for high-traffic
        ]
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Simulate high traffic (10,000 requests)
    echo "  Simulating 10,000 requests... ";
    $startTime = microtime(true);
    
    for ($i = 0; $i < 10000; $i++) {
        // Simulate random request metrics
        $statusCodes = ['200', '301', '302', '404', '500'];
        $methods = ['GET', 'POST', 'PUT', 'DELETE'];
        $routes = ['seller_profile', 'product', 'static', 'default'];
        
        $collector->incrementCounter('requests_total', [
            'method' => $methods[array_rand($methods)],
            'route' => $routes[array_rand($routes)],
            'status' => $statusCodes[array_rand($statusCodes)]
        ]);
        
        // Simulate random timing
        $collector->observeHistogram('request_duration_seconds', mt_rand(1, 1000) / 1000, [
            'route' => $routes[array_rand($routes)]
        ]);
        
        // Only persist occasionally to simulate batched updates
        if ($i % 100 === 0) {
            $collector->persistMetrics();
        }
    }
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $requestsPerSecond = 10000 / $duration;
    
    echo "completed in " . number_format($duration, 2) . " seconds\n";
    echo "  Performance: " . number_format($requestsPerSecond, 2) . " requests/second\n";
    
    // Generate Prometheus output after stress test
    $metricsTime = microtime(true);
    $output = $collector->getPrometheusMetrics();
    $metricsGenTime = microtime(true) - $metricsTime;
    
    echo "  Metrics generation time: " . number_format($metricsGenTime * 1000, 2) . "ms\n";
    
    // Verify metrics are present
    if (!$runner->assertContains('requests_total', $output)) {
        return "Metrics not found after stress test";
    }
    
    // Ensure metrics generation is under 100ms even with high volume
    return $runner->assert($metricsGenTime < 0.1, 
        "Metrics generation too slow: " . number_format($metricsGenTime * 1000, 2) . "ms (should be <100ms)");
});

// Test 7: Cache invalidation
$runner->addTest('cache_invalidation', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector with test config
    $config = [
        'enabled' => true,
        'retention' => [
            'counters' => 1,  // 1 second TTL for quick expiration
            'gauges' => 1,
            'histograms' => 1
        ]
    ];
    
    // First collector - set values
    $collector1 = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    $collector1->incrementCounter('expiring_metric', ['test' => 'cache'], 42);
    $collector1->persistMetrics();
    
    // Verify metric exists
    $output1 = $collector1->getPrometheusMetrics();
    if (!$runner->assertContains('expiring_metric{test=cache} 42', $output1)) {
        return "Initial metric not found";
    }
    
    // Wait for cache expiration
    echo "  Waiting for cache expiration... ";
    sleep(2);
    echo "done\n";
    
    // Second collector - should start fresh after expiration
    $collector2 = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    $output2 = $collector2->getPrometheusMetrics();
    
    // Metric should be gone or reset
    if (strpos($output2, 'expiring_metric{test=cache} 42') !== false) {
        return "Metric still exists after cache expiration";
    }
    
    return true;
});

// Test 8: Memory usage
$runner->addTest('memory_usage', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Record starting memory
    $startMemory = memory_get_usage();
    
    // Create metrics collector
    $config = [
        'enabled' => true,
        'storage_limit' => 1000  // Limit number of series
    ];
    
    $collector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    
    // Add a reasonable number of metrics (1000 series)
    echo "  Testing memory usage with 1000 metric series... ";
    for ($i = 0; $i < 100; $i++) {
        for ($j = 0; $j < 10; $j++) {
            $collector->incrementCounter('memory_test', [
                'dimension1' => "value{$i}",
                'dimension2' => "value{$j}"
            ]);
        }
    }
    
    // Force metrics serialization
    $output = $collector->getPrometheusMetrics();
    $collector->persistMetrics();
    
    // Check memory usage
    $endMemory = memory_get_usage();
    $memoryUsed = $endMemory - $startMemory;
    $memoryPerSeries = $memoryUsed / 1000;
    
    echo "done\n";
    echo "  Memory used: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB\n";
    echo "  Memory per series: " . number_format($memoryPerSeries / 1024, 2) . " KB\n";
    
    // Ensure memory usage is reasonable (less than 20MB total, less than 20KB per series)
    if (!$runner->assert($memoryUsed < 20 * 1024 * 1024, 
        "Total memory usage too high: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB")) {
        return false;
    }
    
    return $runner->assert($memoryPerSeries < 20 * 1024,
        "Memory per series too high: " . number_format($memoryPerSeries / 1024, 2) . " KB");
});

// Test 9: Authentication testing for metrics endpoint
$runner->addTest('metrics_authentication', function() use ($runner) {
    // Create simulated request environment
    $metricsConfig = [
        'endpoint' => [
            'auth' => [
                'enabled' => true,
                'username' => 'prometheus',
                'password' => 'secret',
                'ip_whitelist' => ['127.0.0.1', '192.168.1.0/24']
            ]
        ]
    ];
    
    // Case 1: Test authentication failure (wrong credentials)
    $_SERVER['PHP_AUTH_USER'] = 'wrong';
    $_SERVER['PHP_AUTH_PW'] = 'wrong';
    $_SERVER['REMOTE_ADDR'] = '8.8.8.8'; // Not in whitelist
    
    $authResult1 = checkMetricsAuth($metricsConfig);
    if (!$runner->assert($authResult1 === false, "Authentication should fail with wrong credentials")) {
        return false;
    }
    
    // Case 2: Test authentication success with correct credentials
    $_SERVER['PHP_AUTH_USER'] = 'prometheus';
    $_SERVER['PHP_AUTH_PW'] = 'secret';
    $_SERVER['REMOTE_ADDR'] = '8.8.8.8'; // Not in whitelist
    
    $authResult2 = checkMetricsAuth($metricsConfig);
    if (!$runner->assert($authResult2 === true, "Authentication should succeed with correct credentials")) {
        return false;
    }
    
    // Case 3: Test IP whitelist (no credentials needed)
    unset($_SERVER['PHP_AUTH_USER']);
    unset($_SERVER['PHP_AUTH_PW']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // In whitelist
    
    $authResult3 = checkMetricsAuth($metricsConfig);
    if (!$runner->assert($authResult3 === true, "Authentication should succeed for whitelisted IP")) {
        return false;
    }
    
    // Case 4: Test CIDR range
    $_SERVER['REMOTE_ADDR'] = '192.168.1.42'; // In whitelist range
    
    $authResult4 = checkMetricsAuth($metricsConfig);
    if (!$runner->assert($authResult4 === true, "Authentication should succeed for IP in CIDR range")) {
        return false;
    }
    
    return true;
});

// Test 10: Concurrent access simulation
$runner->addTest('concurrent_access', function() use ($runner) {
    // Create test dependencies
    $logger = new MockLogger();
    $cache = new MockCache();
    
    // Create metrics collector
    $config = [
        'enabled' => true
    ];
    
    // Simulate concurrent threads
    echo "  Simulating concurrent access with 5 collector instances...\n";
    
    $collectors = [];
    for ($i = 0; $i < 5; $i++) {
        $collectors[$i] = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    }
    
    // Each collector updates the same counter
    foreach ($collectors as $i => $collector) {
        $collector->incrementCounter('concurrent_test', ['instance' => "thread-{$i}"], 10);
        $collector->persistMetrics();
    }
    
    // Create fresh collector to read the final state
    $finalCollector = new Willhaben\Metrics\MetricsCollector($config, $logger, $cache);
    $output = $finalCollector->getPrometheusMetrics();
    
    // Verify all threads' data is present
    for ($i = 0; $i < 5; $i++) {
        if (!$runner->assertContains("concurrent_test{instance=thread-{$i}} 10", $output)) {
            return "Data from thread {$i} is missing";
        }
    }
    
    // Now test a shared counter (all threads increment same metric)
    $sharedCache = new MockCache(); // New cache to start fresh
    
    // Create collectors sharing a counter
    $sharedCollectors = [];
    for ($i = 0; $i < 5; $i++) {
        $sharedCollectors[$i] = new Willhaben\Metrics\MetricsCollector($config, $logger, $sharedCache);
    }
    
    // Each collector updates the same counter
    foreach ($sharedCollectors as $collector) {
        $collector->incrementCounter('shared_counter', [], 5);
        $collector->persistMetrics();
    }
    
    // Create fresh collector to read the final state
    $finalSharedCollector = new Willhaben\Metrics\MetricsCollector($config, $logger, $sharedCache);
    $sharedOutput = $finalSharedCollector->getPrometheusMetrics();
    
    // Verify the shared counter is the sum of all increments (5*5=25)
    return $runner->assertContains('shared_counter 25', $sharedOutput);
});

/**
 * Helper function to simulate metrics authentication checks
 */
function checkMetricsAuth(array $config): bool {
    // Check IP whitelist
    $ipWhitelist = $config['endpoint']['auth']['ip_whitelist'] ?? [];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    
    foreach ($ipWhitelist as $allowedIp) {
        // Simple exact match
        if ($clientIp === $allowedIp) {
            return true;
        }
        
        // CIDR match
        if (strpos($allowedIp, '/') !== false) {
            list($subnet, $bits) = explode('/', $allowedIp);
            
            // Convert IP and subnet to long
            $ipLong = ip2long($clientIp);
            $subnetLong = ip2long($subnet);
            
            if ($ipLong !== false && $subnetLong !== false) {
                // Create mask based on
