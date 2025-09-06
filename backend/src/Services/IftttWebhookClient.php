<?php

declare(strict_types=1);

namespace HotTubController\Services;

use RuntimeException;

/**
 * IFTTT Webhook API Client
 * 
 * Provides simple, reliable control of hot tub equipment through 
 * IFTTT webhook triggers and SmartLife automation scenes.
 */
class IftttWebhookClient
{
    private string $apiKey;
    private int $timeout;
    private string $baseUrl = 'https://maker.ifttt.com/trigger';
    
    public function __construct(string $apiKey, int $timeout = 30)
    {
        if (empty($apiKey)) {
            throw new RuntimeException('IFTTT API key cannot be empty');
        }
        
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }
    
    /**
     * Trigger IFTTT webhook event
     * 
     * @param string $eventName The IFTTT event name to trigger
     * @return bool True on success, false on failure
     */
    public function trigger(string $eventName): bool
    {
        $url = sprintf(
            '%s/%s/with/key/%s',
            $this->baseUrl,
            $eventName,
            $this->apiKey
        );
        
        $startTime = microtime(true);
        
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $this->timeout,
                    'header' => [
                        'User-Agent: HotTubController/1.0',
                        'Accept: */*'
                    ]
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            $duration = round((microtime(true) - $startTime) * 1000);
            
            if ($response === false) {
                $this->logError($eventName, 'Request failed', null, $duration);
                return false;
            }
            
            // Check HTTP response code
            $httpCode = $this->getHttpResponseCode($http_response_header ?? []);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $this->logSuccess($eventName, $httpCode, $duration);
                return true;
            }
            
            $this->logError($eventName, 'HTTP error', $httpCode, $duration);
            return false;
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->logError($eventName, 'Exception: ' . $e->getMessage(), null, $duration);
            return false;
        }
    }
    
    /**
     * Start hot tub heating sequence
     * 
     * Triggers SmartLife scene that:
     * 1. Starts water circulation pump
     * 2. Waits for proper circulation
     * 3. Activates heating element
     * 
     * @return bool True on success, false on failure
     */
    public function startHeating(): bool
    {
        return $this->trigger('hot-tub-heat-on');
    }
    
    /**
     * Stop hot tub heating sequence
     * 
     * Triggers SmartLife scene that:
     * 1. Turns off heating element immediately
     * 2. Continues pump for heater cooling
     * 3. Stops pump after cooling period
     * 
     * @return bool True on success, false on failure
     */
    public function stopHeating(): bool
    {
        return $this->trigger('hot-tub-heat-off');
    }
    
    /**
     * Start hot tub ionizer system
     * 
     * @return bool True on success, false on failure
     */
    public function startIonizer(): bool
    {
        return $this->trigger('turn-on-hot-tub-ionizer');
    }
    
    /**
     * Stop hot tub ionizer system
     * 
     * @return bool True on success, false on failure
     */
    public function stopIonizer(): bool
    {
        return $this->trigger('turn-off-hot-tub-ionizer');
    }
    
    /**
     * Test IFTTT webhook connectivity
     * 
     * Note: This will actually trigger webhooks. In production,
     * you might want a dedicated test webhook that does nothing.
     * 
     * @return array Test results
     */
    public function testConnectivity(): array
    {
        $results = [
            'available' => false,
            'tested_at' => date('Y-m-d H:i:s'),
            'response_time_ms' => null,
            'error' => null
        ];
        
        $startTime = microtime(true);
        
        try {
            // Test with a minimal request to IFTTT
            $testUrl = $this->baseUrl . '/test-connectivity/with/key/' . $this->apiKey;
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => 'User-Agent: HotTubController/1.0'
                ]
            ]);
            
            $response = @file_get_contents($testUrl, false, $context);
            $results['response_time_ms'] = round((microtime(true) - $startTime) * 1000);
            
            if ($response !== false) {
                $httpCode = $this->getHttpResponseCode($http_response_header ?? []);
                $results['available'] = $httpCode >= 200 && $httpCode < 500;
            }
            
        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            $results['response_time_ms'] = round((microtime(true) - $startTime) * 1000);
        }
        
        return $results;
    }
    
    /**
     * Extract HTTP response code from headers
     */
    private function getHttpResponseCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }
        
        // Parse first header line for status code
        preg_match('/HTTP\/\d+\.\d+ (\d+)/', $headers[0], $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
    
    /**
     * Log successful webhook trigger
     */
    private function logSuccess(string $eventName, int $httpCode, int $durationMs): void
    {
        error_log(sprintf(
            "IFTTT SUCCESS: Event '%s' triggered successfully (HTTP %d, %dms)",
            $eventName,
            $httpCode,
            $durationMs
        ));
    }
    
    /**
     * Log failed webhook trigger
     */
    private function logError(string $eventName, string $error, ?int $httpCode, int $durationMs): void
    {
        $httpInfo = $httpCode ? " (HTTP {$httpCode})" : '';
        
        error_log(sprintf(
            "IFTTT ERROR: Event '%s' failed - %s%s (%dms)",
            $eventName,
            $error,
            $httpInfo,
            $durationMs
        ));
    }
}