<?php
/**
 * Metrics Endpoint
 * 
 * Exposes application metrics in Prometheus format.
 */

namespace Willhaben\Metrics;

/**
 * MetricsEndpoint class for exposing metrics
 */
class MetricsEndpoint
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
     * Metrics collector
     *
     * @var MetricsCollector
     */
    protected $collector;
    
    /**
     * Cache service
     *
     * @var \Willhaben\Cache\CacheInterface
     */
    protected $cache;
    
    /**
     * Security headers middleware
     *
     * @var \Willhaben\Middleware\SecurityHeadersMiddleware
     */
    protected $securityHeaders;
    
    /**
     * Constructor
     *
     * @param array $config Metrics configuration
     * @param \Willhaben\Logger\LoggerInterface $logger Logger instance
     * @param MetricsCollector $collector Metrics collector
     * @param \Willhaben\Cache\CacheInterface $cache Cache service
     * @param \Willhaben\Middleware\SecurityHeadersMiddleware $securityHeaders Security headers middleware
     */
    public function __construct(
        array $config,
        \Willhaben\Logger\LoggerInterface $logger,
        MetricsCollector $collector,
        \Willhaben\Cache\CacheInterface $cache,
        \Willhaben\Middleware\SecurityHeadersMiddleware $securityHeaders
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->collector = $collector;
        $this->cache = $cache;
        $this->securityHeaders = $securityHeaders;
    }
    
    /**
     * Handle metrics request
     *
     * @return void
     */
    public function handle(): void
    {
        // Check if metrics endpoint is enabled
        if (!($this->config['endpoint']['enabled'] ?? true)) {
            $this->sendError(404, 'Metrics endpoint disabled');
            return;
        }
        
        // Verify authentication
        if (($this->config['endpoint']['auth']['enabled'] ?? true) && !$this->authenticate()) {
            $this->sendError(401, 'Unauthorized', true);
            return;
        }
        
        try {
            // Check for cached metrics
            $cacheKey = 'metrics:endpoint:response';
            $cachedResponse = $this->cache->get($cacheKey);
            
            // Return cached response if available
            if ($cachedResponse !== null) {
                $this->sendResponse($cachedResponse);
                return;
            }
            
            // Set timeout for metrics generation
            $timeout = $this->config['endpoint']['timeout'] ?? 5;
            set_time_limit($timeout);
            
            // Generate metrics
            $metrics = $this->collector->getPrometheusMetrics();
            
            // Cache the response
            $cacheTtl = $this->config['endpoint']['cache_ttl'] ?? 10;
            $this->cache->set($cacheKey, $metrics, $cacheTtl);
            
            // Send response
            $this->sendResponse($metrics);
        } catch (\Throwable $e) {
            $this->logger->error('Error generating metrics response: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()

