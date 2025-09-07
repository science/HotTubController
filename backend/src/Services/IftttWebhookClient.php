<?php

declare(strict_types=1);

namespace HotTubController\Services;

use RuntimeException;

/**
 * IFTTT Webhook API Client
 * 
 * Provides simple, reliable control of hot tub equipment through 
 * IFTTT webhook triggers and SmartLife automation scenes.
 * 
 * Safety Features:
 * - Test mode detection when API key is missing
 * - Dry run mode for testing without actual API calls
 * - Comprehensive audit logging for all operations
 */
class IftttWebhookClient
{
    private ?string $apiKey;
    private int $timeout;
    private bool $dryRun;
    private bool $testMode;
    private string $baseUrl = 'https://maker.ifttt.com/trigger';
    private string $auditLogPath;
    
    /**
     * @param string|null $apiKey IFTTT webhook key (null triggers test mode)
     * @param int $timeout Request timeout in seconds
     * @param bool $dryRun If true, log operations but don't make HTTP calls
     * @param string|null $auditLogPath Path to audit log file
     */
    public function __construct(
        ?string $apiKey, 
        int $timeout = 30, 
        bool $dryRun = false,
        ?string $auditLogPath = null
    ) {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->dryRun = $dryRun;
        $this->testMode = empty($apiKey);
        $this->auditLogPath = $auditLogPath ?? (__DIR__ . '/../../storage/logs/ifttt-audit.log');
        
        // Log initialization
        $this->auditLog('INIT', [
            'test_mode' => $this->testMode,
            'dry_run' => $this->dryRun,
            'has_api_key' => !empty($apiKey),
            'timeout' => $timeout
        ]);
        
        if ($this->testMode && !$this->dryRun) {
            $this->auditLog('SAFETY', [
                'message' => 'Operating in TEST MODE - no hardware will be affected',
                'reason' => 'Missing IFTTT API key'
            ]);
        }
    }
    
    /**
     * Trigger IFTTT webhook event
     * 
     * @param string $eventName The IFTTT event name to trigger
     * @return bool True on success, false on failure
     */
    public function trigger(string $eventName): bool
    {
        // In test mode or dry run, simulate success but don't make actual calls
        if ($this->testMode || $this->dryRun) {
            return $this->simulateTrigger($eventName);
        }
        
        $url = sprintf(
            '%s/%s/with/key/%s',
            $this->baseUrl,
            $eventName,
            $this->apiKey
        );
        
        $startTime = microtime(true);
        
        $this->auditLog('TRIGGER_ATTEMPT', [
            'event_name' => $eventName,
            'url' => $this->sanitizeUrlForLogging($url)
        ]);
        
        try {
            // Use cURL for VCR compatibility
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => 'HotTubController/1.0',
                CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            
            $duration = (int)round((microtime(true) - $startTime) * 1000);
            
            if ($response === false || !empty($curlError)) {
                $this->logError($eventName, 'Request failed: ' . ($curlError ?: 'Unknown cURL error'), null, $duration);
                return false;
            }
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $this->logSuccess($eventName, $httpCode, $duration);
                return true;
            }
            
            $this->logError($eventName, 'HTTP error', $httpCode, $duration);
            return false;
            
        } catch (\Exception $e) {
            $duration = (int)round((microtime(true) - $startTime) * 1000);
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
     * @return array Test results
     */
    public function testConnectivity(): array
    {
        $results = [
            'available' => false,
            'tested_at' => date('Y-m-d H:i:s'),
            'response_time_ms' => null,
            'error' => null,
            'test_mode' => $this->testMode,
            'dry_run' => $this->dryRun
        ];
        
        // In test mode, return simulated results
        if ($this->testMode) {
            $results['available'] = false;
            $results['error'] = 'Test mode - IFTTT API key not available';
            $results['response_time_ms'] = 0;
            $this->auditLog('CONNECTIVITY_TEST', $results);
            return $results;
        }
        
        // In dry run mode, simulate successful connection
        if ($this->dryRun) {
            $results['available'] = true;
            $results['response_time_ms'] = 150; // Simulated response time
            $this->auditLog('CONNECTIVITY_TEST_SIMULATED', $results);
            return $results;
        }
        
        $startTime = microtime(true);
        
        try {
            // Test with a minimal request to IFTTT using cURL
            $testUrl = $this->baseUrl . '/test-connectivity/with/key/' . $this->apiKey;
            
            $this->auditLog('CONNECTIVITY_TEST_ATTEMPT', [
                'url' => $this->sanitizeUrlForLogging($testUrl)
            ]);
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $testUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'HotTubController/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            
            $results['response_time_ms'] = (int)round((microtime(true) - $startTime) * 1000);
            
            if ($response !== false && empty($curlError)) {
                $results['available'] = $httpCode >= 200 && $httpCode < 500;
            }
            
        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            $results['response_time_ms'] = (int)round((microtime(true) - $startTime) * 1000);
        }
        
        $this->auditLog('CONNECTIVITY_TEST_RESULT', $results);
        return $results;
    }
    
    /**
     * Check if client is operating in test mode
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }
    
    /**
     * Check if client is operating in dry run mode
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
    
    /**
     * Simulate a webhook trigger for testing
     */
    private function simulateTrigger(string $eventName): bool
    {
        $startTime = microtime(true);
        
        // Simulate processing time
        usleep(100000); // 100ms
        
        $duration = (int)round((microtime(true) - $startTime) * 1000);
        
        $this->auditLog('TRIGGER_SIMULATED', [
            'event_name' => $eventName,
            'duration_ms' => $duration,
            'test_mode' => $this->testMode,
            'dry_run' => $this->dryRun
        ]);
        
        $this->logSuccess($eventName, 200, $duration, true);
        return true;
    }
    
    /**
     * Sanitize URL for logging by masking the API key
     */
    private function sanitizeUrlForLogging(string $url): string
    {
        return preg_replace('/\/key\/[^\/]+/', '/key/***MASKED***', $url);
    }
    
    /**
     * Write to audit log with timestamp and context
     */
    private function auditLog(string $action, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'context' => $context,
            'environment' => $_ENV['APP_ENV'] ?? 'unknown'
        ];
        
        $logLine = json_encode($logEntry) . "\n";
        
        // Ensure log directory exists
        $logDir = dirname($this->auditLogPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        @file_put_contents($this->auditLogPath, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    
    /**
     * Log successful webhook trigger
     */
    private function logSuccess(string $eventName, int $httpCode, int $durationMs, bool $simulated = false): void
    {
        $prefix = $simulated ? "IFTTT SIMULATED SUCCESS" : "IFTTT SUCCESS";
        $message = sprintf(
            "%s: Event '%s' triggered successfully (HTTP %d, %dms)",
            $prefix,
            $eventName,
            $httpCode,
            $durationMs
        );
        
        error_log($message);
        
        $this->auditLog('TRIGGER_SUCCESS', [
            'event_name' => $eventName,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'simulated' => $simulated
        ]);
    }
    
    /**
     * Log failed webhook trigger
     */
    private function logError(string $eventName, string $error, ?int $httpCode, int $durationMs, bool $simulated = false): void
    {
        $httpInfo = $httpCode ? " (HTTP {$httpCode})" : '';
        $prefix = $simulated ? "IFTTT SIMULATED ERROR" : "IFTTT ERROR";
        $message = sprintf(
            "%s: Event '%s' failed - %s%s (%dms)",
            $prefix,
            $eventName,
            $error,
            $httpInfo,
            $durationMs
        );
        
        error_log($message);
        
        $this->auditLog('TRIGGER_ERROR', [
            'event_name' => $eventName,
            'error' => $error,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'simulated' => $simulated
        ]);
    }
}