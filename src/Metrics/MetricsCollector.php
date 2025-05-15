<?php
/**
 * Metrics Collector
 * 
 * Collects performance and operational metrics for monitoring.
 */

namespace Willhaben\Metrics;

/**
 * MetricsCollector class for tracking application metrics
 */
class MetricsCollector
{
    /**
     * Metrics configuration
     *
     * @var array
     */
    protected $config;
    
    /**
     * Logger instance
     *
     * @var \Willhaben\Logger\LoggerInterface
     */
    protected $logger;
    
    /**
     * Cache service for storing metrics
     *
     * @var \Willhaben\Cache\CacheInterface
     */
    protected $cache;
    
    /**
     * Request start time
     *
     * @var float
     */
    protected $requestStartTime;
    
    /**
     * Whether metrics are enabled
     *
     * @var bool
     */
    protected $enabled;
    
    /**
     * Current metrics memory buffer
     *
     * @var array
     */
    protected $metricsBuffer = [];
    
    /**
     * Counter metrics
     *
     * @var array
     */
    protected $counters = [];
    
    /**
     * Gauge metrics
     *
     * @var array
     */
    protected $gauges = [];
    
    /**
     * Histogram metrics
     *
     * @var array
     */
    protected $histograms = [];
    
    /**
     * Constructor
     *
     * @param array $config Metrics configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     * @param \Willhaben\Cache\CacheInterface $cache Cache service
     */
    public function __construct(array $config, \Willhaben\Logger\LoggerInterface $logger, \Willhaben\Cache\CacheInterface $cache)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->enabled = $config['enabled'] ?? true;
        
        // Record request start time
        $this->requestStartTime = microtime(true);
        
        // Initialize metrics
        $this->initializeMetrics();
        
        // Load existing metrics from cache
        $this->loadMetricsFromCache();
    }
    
    /**
     * Initialize metrics definitions
     *
     * @return void
     */
    protected function initializeMetrics(): void
    {
        // Initialize counters
        $this->counters = [
            'requests_total' => [
                'help' => 'Total number of HTTP requests',
                'labels' => ['method', 'route', 'status']
            ],
            'redirects_total' => [
                'help' => 'Total number of redirects',
                'labels' => ['type', 'status']
            ],
            'errors_total' => [
                'help' => 'Total number of errors',
                'labels' => ['type', 'code']
            ],
            'cache_operations_total' => [
                'help' => 'Total number of cache operations',
                'labels' => ['operation', 'result']
            ],
            'rate_limit_hits_total' => [
                'help' => 'Total number of rate limit hits',
                'labels' => ['route']
            ],
            'static_files_served_total' => [
                'help' => 'Total number of static files served',
                'labels' => ['type', 'compressed']
            ]
        ];
        
        // Initialize gauges
        $this->gauges = [
            'response_time_seconds' => [
                'help' => 'Response time in seconds',
                'labels' => ['route']
            ],
            'memory_usage_bytes' => [
                'help' => 'Memory usage in bytes',
                'labels' => []
            ],
            'seller_cache_size' => [
                'help' => 'Number of sellers in cache',
                'labels' => []
            ],
            'active_rate_limits' => [
                'help' => 'Number of active rate limits',
                'labels' => ['route']
            ]
        ];
        
        // Initialize histograms
        $this->histograms = [
            'request_duration_seconds' => [
                'help' => 'Request duration in seconds',
                'labels' => ['route'],
                'buckets' => [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
            ],
            'response_size_bytes' => [
                'help' => 'Response size in bytes',
                'labels' => ['route'],
                'buckets' => [100, 1000, 10000, 100000, 1000000, 10000000]
            ]
        ];
    }
    
    /**
     * Load metrics from cache
     *
     * @return void
     */
    protected function loadMetricsFromCache(): void
    {
        if (!$this->enabled) {
            return;
        }
        
        try {
            // Load counters from cache
            $cachedCounters = $this->cache->get('metrics:counters');
            if (is_array($cachedCounters)) {
                $this->metricsBuffer['counters'] = $cachedCounters;
            }
            
            // Load histograms from cache
            $cachedHistograms = $this->cache->get('metrics:histograms');
            if (is_array($cachedHistograms)) {
                $this->metricsBuffer['histograms'] = $cachedHistograms;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load metrics from cache: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            
            // Initialize empty metrics buffer
            $this->metricsBuffer = [
                'counters' => [],
                'histograms' => []
            ];
        }
    }
    
    /**
     * Increment a counter metric
     *
     * @param string $name Metric name
     * @param array $labelValues Label values
     * @param int $value Increment value (default: 1)
     * @return void
     */
    public function incrementCounter(string $name, array $labelValues = [], int $value = 1): void
    {
        if (!$this->enabled || $value <= 0) {
            return;
        }
        
        if (!isset($this->counters[$name])) {
            $this->logger->warning('Attempted to increment unknown counter', [
                'name' => $name
            ]);
            return;
        }
        
        try {
            // Create a label string
            $labelString = $this->getLabelString($name, $labelValues, 'counter');
            
            // Initialize counter if it doesn't exist
            if (!isset($this->metricsBuffer['counters'][$name][$labelString])) {
                $this->metricsBuffer['counters'][$name][$labelString] = 0;
            }
            
            // Increment counter
            $this->metricsBuffer['counters'][$name][$labelString] += $value;
        } catch (\Throwable $e) {
            // Log but don't let metrics break the application
            $this->logger->warning('Failed to increment counter: ' . $e->getMessage(), [
                'name' => $name,
                'labels' => $labelValues,
                'exception' => get_class($e)
            ]);
        }
    }
    
    /**
     * Set a gauge metric
     *
     * @param string $name Metric name
     * @param float $value Gauge value
     * @param array $labelValues Label values
     * @return void
     */
    public function setGauge(string $name, float $value, array $labelValues = []): void
    {
        if (!$this->enabled) {
            return;
        }
        
        if (!isset($this->gauges[$name])) {
            $this->logger->warning('Attempted to set unknown gauge', [
                'name' => $name
            ]);
            return;
        }
        
        try {
            // Create a label string
            $labelString = $this->getLabelString($name, $labelValues, 'gauge');
            
            // Set gauge value
            $this->metricsBuffer['gauges'][$name][$labelString] = $value;
        } catch (\Throwable $e) {
            // Log but don't let metrics break the application
            $this->logger->warning('Failed to set gauge: ' . $e->getMessage(), [
                'name' => $name,
                'value' => $value,
                'labels' => $labelValues,
                'exception' => get_class($e)
            ]);
        }
    }
    
    /**
     * Record a value in a histogram
     *
     * @param string $name Metric name
     * @param float $value Value to record
     * @param array $labelValues Label values
     * @return void
     */
    public function observeHistogram(string $name, float $value, array $labelValues = []): void
    {
        if (!$this->enabled) {
            return;
        }
        
        if (!isset($this->histograms[$name])) {
            $this->logger->warning('Attempted to observe unknown histogram', [
                'name' => $name
            ]);
            return;
        }
        
        try {
            // Create a label string
            $labelString = $this->getLabelString($name, $labelValues, 'histogram');
            
            // Initialize histogram if it doesn't exist
            if (!isset($this->metricsBuffer['histograms'][$name][$labelString])) {
                $this->metricsBuffer['histograms'][$name][$labelString] = [
                    'count' => 0,
                    'sum' => 0,
                    'buckets' => []
                ];
                
                // Initialize buckets
                foreach ($this->histograms[$name]['buckets'] as $bucket) {
                    $this->metricsBuffer['histograms'][$name][$labelString]['buckets'][(string)$bucket] = 0;
                }
            }
            
            // Update histogram data
            $this->metricsBuffer['histograms'][$name][$labelString]['count']++;
            $this->metricsBuffer['histograms'][$name][$labelString]['sum'] += $value;
            
            // Update buckets
            foreach ($this->histograms[$name]['buckets'] as $bucket) {
                if ($value <= $bucket) {
                    $this->metricsBuffer['histograms'][$name][$labelString]['buckets'][(string)$bucket]++;
                }
            }
        } catch (\Throwable $e) {
            // Log but don't let metrics break the application
            $this->logger->warning('Failed to observe histogram: ' . $e->getMessage(), [
                'name' => $name,
                'value' => $value,
                'labels' => $labelValues,
                'exception' => get_class($e)
            ]);
        }
    }
    
    /**
     * Record request metrics at the end of a request
     *
     * @param int $statusCode HTTP status code
     * @param string $route Route identifier
     * @param string $method HTTP method
     * @param int $responseSize Response size in bytes
     * @return void
     */
    public function recordRequestMetrics(int $statusCode, string $route = 'unknown', string $method = 'GET', int $responseSize = 0): void
    {
        if (!$this->enabled) {
            return;
        }
        
        try {
            // Calculate request duration
            $duration = microtime(true) - $this->requestStartTime;
            
            // Record request counter
            $this->incrementCounter('requests_total', [
                'method' => $method,
                'route' => $route,
                'status' => (string)$statusCode
            ]);
            
            // Record request duration
            $this->observeHistogram('request_duration_seconds', $duration, [
                'route' => $route
            ]);
            
            // Record response size
            if ($responseSize > 0) {
                $this->observeHistogram('response_size_bytes', $responseSize, [
                    'route' => $route
                ]);
            }
            
            // Record memory usage
            $this->setGauge('memory_usage_bytes', memory_get_peak_usage(true));
            
            // Persist metrics to cache
            $this->persistMetrics();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record request metrics: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'route' => $route
            ]);
        }
    }
    
    /**
     * Record cache operation metrics
     *
     * @param string $operation Operation name (get, set, delete)
     * @param string $result Result (hit, miss, success, failure)
     * @return void
     */
    public function recordCacheOperation(string $operation, string $result): void
    {
        $this->incrementCounter('cache_operations_total', [
            'operation' => $operation,
            'result' => $result
        ]);
    }
    
    /**
     * Record redirect metrics
     *
     * @param string $type Redirect type (seller, product, other)
     * @param int $status HTTP status code
     * @return void
     */
    public function recordRedirect(string $type, int $status): void
    {
        $this->incrementCounter('redirects_total', [
            'type' => $type,
            'status' => (string)$status
        ]);
    }
    
    /**
     * Record error metrics
     *
     * @param string $type Error type (validation, server, client)
     * @param int $code Error code
     * @return void
     */
    public function recordError(string $type, int $code): void
    {
        $this->incrementCounter('errors_total', [
            'type' => $type,
            'code' => (string)$code
        ]);
    }
    
    /**
     * Record rate limit hit
     *
     * @param string $route Route that was rate limited
     * @return void
     */
    public function recordRateLimitHit(string $route): void
    {
        $this->incrementCounter('rate_limit_hits_total', [
            'route' => $route
        ]);
    }
    
    /**
     * Record static file serving metrics
     *
     * @param string $type File type (extension)
     * @param bool $compressed Whether the file was served compressed
     * @return void
     */
    public function recordStaticFileServed(string $type, bool $compressed = false): void
    {
        $this->incrementCounter('static_files_served_total', [
            'type' => $type,
            'compressed' => $compressed ? 'true' : 'false'
        ]);
    }
    
    /**
     * Persist metrics to cache
     *
     * @return void
     */
    public function persistMetrics(): void
    {
        if (!$this->enabled) {
            return;
        }
        
        try {
            // Persist counters
            if (!empty($this->metricsBuffer['counters'])) {
                $this->cache->set('metrics:counters', $this->metricsBuffer['counters'], $this->config['retention']['counters'] ?? 86400);
            }
            
            // Persist histograms
            if (!empty($this->metricsBuffer['histograms'])) {
                $this->cache->set('metrics:histograms', $this->metricsBuffer['histograms'], $this->config['retention']['histograms'] ?? 43200);
            }
            
            // Persist gauges
            if (!empty($this->metricsBuffer['gauges'])) {
                $this->cache->set('metrics:gauges', $this->metricsBuffer['gauges'], $this->config['retention']['gauges'] ?? 3600);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to persist metrics: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
        }
    }
    
    /**
     * Generate a string representation of label values
     *
     * @param string $name Metric name
     * @param array $labelValues Label values
     * @param string $type Metric type
     * @return string
     */
    protected function getLabelString(string $name, array $labelValues, string $type): string
    {
        $labels = [];
        
        // Get expected labels based on metric type
        $expectedLabels = [];
        if ($type === 'counter' && isset($this->counters[$name])) {
            $expectedLabels = $this->counters[$name]['labels'];
        } elseif ($type === 'gauge' && isset($this->gauges[$name])) {
            $expectedLabels = $this->gauges[$name]['labels'];
        } elseif ($type === 'histogram' && isset($this->histograms[$name])) {
            $expectedLabels = $this->histograms[$name]['labels'];
        }
        
        // Ensure all expected labels have values
        foreach ($expectedLabels as $label) {
            $labels[$label] = $labelValues[$label] ?? 'unknown';
        }
        
        // Create a unique string representation of the labels
        if (empty($labels)) {
            return '';
        }
        
        $parts = [];
        ksort($labels); // Sort by label name for consistent ordering
        
        foreach ($labels as $label => $value) {
            $parts[] = $label . '=' . $value;
        }
        
        return implode(',', $parts);
    }
    
    /**
     * Get metrics in Prometheus format
     *
     * @return string
     */
    public function getPrometheusMetrics(): string
    {
        if (!$this->enabled) {
            return "# Metrics collection is disabled\n";
        }
        
        try {
            $output = [];
            
            // Add counters
            foreach ($this->counters as $name => $def) {
                $output[] = "# HELP {$name} {$def['help']}";
                $output[] = "# TYPE {$name} counter";
                
                if (isset($this->metricsBuffer['counters'][$name])) {
                    foreach ($this->metricsBuffer['counters'][$name] as $labels => $value) {
                        $labelStr = $labels ? '{' . $labels . '}' : '';
                        $output[] = "{$name}{$labelStr} {$value}";
                    }
                }
                
                $output[] = '';
            }
            
            // Add gauges
            foreach ($this->gauges as $name => $def) {
                $output[] = "# HELP {$name} {$def['help']}";
                $output[] = "# TYPE {$name} gauge";
                
                if (isset($this->metricsBuffer['gauges'][$name])) {
                    foreach ($this->metricsBuffer['gauges'][$name] as $labels => $value) {
                        $labelStr = $labels ? '{' . $labels . '}' : '';
                        $output[] = "{$name}{$labelStr} {$value}";
                    }
                }
                
                $output[] = '';
            }
            
            // Add histograms
            foreach ($this->histograms as $name => $def) {
                $output[] = "# HELP {$name} {$def['help']}";
                $output[] = "# TYPE {$name} histogram";
                
                if (isset($this->metricsBuffer['histograms'][$name])) {
                    foreach ($this->metricsBuffer['histograms'][$name] as $labels => $data) {
                        $labelStr = $labels ? '{' . $labels . '}' : '';
                        
                        // Add sum
                        $output[] = "{$name}_sum{$labelStr} {$data['sum']}";
                        
                        // Add count
                        $output[] = "{$name}_count{$labelStr} {$data['count']}";
                        
                        // Add buckets
                        foreach ($data['buckets'] as $bucket => $count) {
                            $bucketLabel = $labels ? $labels . ',' : '';
                            $bucketLabel .= "le=\"{$bucket}\"";
                            $output[] = "{$name}_bucket{" . $bucketLabel . "} {$count}";
                        }
                        
                        // Add +Inf bucket
                        $infLabel = $labels ? $labels . ',' : '';
                        $infLabel .= 'le="+Inf"';
                        $output[] = "{$name}_bucket{" . $infLabel . "} {$data['count']}";
                    }
                }
                
                $output[] = '';
            }
            
            return implode("\n", $output);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate Prometheus metrics: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return "# Error generating metrics: {$e->getMessage()}\n";
        }
    }
    
    /**
     * Reset metrics buffer (for testing)
     *
     * @return void
     */
    public function resetMetrics(): void
    {
        $this->metricsBuffer = [
            'counters' => [],
            'gauges' => [],
            'histograms' => []
        ];
    }
}
