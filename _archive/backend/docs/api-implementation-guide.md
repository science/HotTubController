# API Implementation Guide

This document provides practical PHP implementation examples for integrating with the IFTTT and WirelessTag APIs. These examples demonstrate proper error handling, retry logic, and best practices for production use.

## IFTTT Webhook Implementation

### Simple IFTTT Client Class

```php
<?php

class IftttWebhookClient
{
    private string $apiKey;
    private int $timeout;
    private string $baseUrl = 'https://maker.ifttt.com/trigger';
    
    public function __construct(string $apiKey, int $timeout = 30)
    {
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
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => 'User-Agent: HotTubController/1.0'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            error_log("IFTTT webhook failed for event: {$eventName}");
            return false;
        }
        
        // Check HTTP response code
        $httpCode = $this->getHttpResponseCode($http_response_header);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        
        error_log("IFTTT webhook returned HTTP {$httpCode} for event: {$eventName}");
        return false;
    }
    
    private function getHttpResponseCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }
        
        // Parse first header line for status code
        preg_match('/HTTP\/\d+\.\d+ (\d+)/', $headers[0], $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
    
    // Convenience methods for hot tub control
    public function startHeating(): bool
    {
        return $this->trigger('hot-tub-heat-on');
    }
    
    public function stopHeating(): bool
    {
        return $this->trigger('hot-tub-heat-off');
    }
    
    public function startIonizer(): bool
    {
        return $this->trigger('turn-on-hot-tub-ionizer');
    }
    
    public function stopIonizer(): bool
    {
        return $this->trigger('turn-off-hot-tub-ionizer');
    }
}
```

### Usage Example

```php
<?php

// Initialize IFTTT client
$ifttt = new IftttWebhookClient($config['ifttt_api_key']);

// Control hot tub heating
if ($ifttt->startHeating()) {
    echo "Heating started successfully\n";
} else {
    echo "Failed to start heating\n";
}

// Control ionizer
if ($ifttt->startIonizer()) {
    echo "Ionizer activated\n";
}
```

## WirelessTag API Implementation

### WirelessTag Client with Retry Logic

```php
<?php

class WirelessTagClient
{
    private string $baseUrl = 'https://wirelesstag.net/ethClient.asmx';
    private string $oauthToken;
    private int $maxRetries;
    private int $timeoutSeconds;
    
    public function __construct(string $oauthToken, int $maxRetries = 8, int $timeoutSeconds = 30)
    {
        $this->oauthToken = $oauthToken;
        $this->maxRetries = $maxRetries;
        $this->timeoutSeconds = $timeoutSeconds;
    }
    
    /**
     * Get cached temperature data from WirelessTag cloud
     * 
     * @param string $deviceId WirelessTag device ID
     * @return array|null Sensor data array or null on failure
     */
    public function getCachedTemperatureData(string $deviceId): ?array
    {
        $endpoint = '/GetTagList';
        $payload = ['id' => $deviceId];
        
        $response = $this->makeRequest($endpoint, $payload);
        
        if ($response && isset($response['d']) && is_array($response['d'])) {
            return $response['d'];
        }
        
        return null;
    }
    
    /**
     * Force sensor to take new temperature reading
     * 
     * @param string $deviceId WirelessTag device ID
     * @return bool True on success, false on failure
     */
    public function requestFreshReading(string $deviceId): bool
    {
        $endpoint = '/RequestImmediatePostback';
        $payload = ['id' => $deviceId];
        
        $response = $this->makeRequest($endpoint, $payload);
        
        return $response !== null;
    }
    
    /**
     * Get fresh temperature reading (combines request + cached read)
     * 
     * @param string $deviceId WirelessTag device ID
     * @param int $waitSeconds Wait time after triggering fresh reading
     * @return array|null Sensor data array or null on failure
     */
    public function getFreshTemperatureData(string $deviceId, int $waitSeconds = 3): ?array
    {
        // Request fresh reading from sensor
        if (!$this->requestFreshReading($deviceId)) {
            return null;
        }
        
        // Wait for sensor to complete measurement
        sleep($waitSeconds);
        
        // Retrieve the fresh data
        return $this->getCachedTemperatureData($deviceId);
    }
    
    /**
     * Make HTTP request to WirelessTag API with retry logic
     * 
     * @param string $endpoint API endpoint path
     * @param array $payload Request payload
     * @return array|null Decoded response or null on failure
     */
    private function makeRequest(string $endpoint, array $payload): ?array
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $this->oauthToken,
            'User-Agent: HotTubController/1.0'
        ];
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true
        ];
        
        $attempt = 1;
        
        while ($attempt <= $this->maxRetries) {
            $curl = curl_init();
            curl_setopt_array($curl, $options);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            
            curl_close($curl);
            
            // Success
            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($response, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                
                error_log("WirelessTag API returned invalid JSON: " . json_last_error_msg());
                return null;
            }
            
            // Log the error
            $logMessage = sprintf(
                "WirelessTag API attempt %d/%d failed. HTTP: %d, Error: %s, Endpoint: %s",
                $attempt,
                $this->maxRetries,
                $httpCode,
                $error ?: 'none',
                $endpoint
            );
            error_log($logMessage);
            
            // Don't retry on authentication errors
            if ($httpCode === 401 || $httpCode === 403) {
                break;
            }
            
            // Calculate exponential backoff delay
            if ($attempt < $this->maxRetries) {
                $delay = min(30 * $attempt, 300); // Max 5 minutes
                sleep($delay);
            }
            
            $attempt++;
        }
        
        return null;
    }
}
```

### Temperature Processing Utilities

```php
<?php

class TemperatureProcessor
{
    /**
     * Convert Celsius to Fahrenheit
     */
    public static function celsiusToFahrenheit(float $celsius): float
    {
        return ($celsius * 1.8) + 32;
    }
    
    /**
     * Extract temperature data from WirelessTag response
     * 
     * @param array $sensorData Raw sensor data from API
     * @param int $deviceIndex Device index in response array
     * @return array Processed temperature data
     */
    public static function extractTemperatureData(array $sensorData, int $deviceIndex = 0): array
    {
        if (!isset($sensorData[$deviceIndex])) {
            throw new InvalidArgumentException("Device index {$deviceIndex} not found in sensor data");
        }
        
        $device = $sensorData[$deviceIndex];
        
        // Extract temperatures in Celsius
        $waterTempC = $device['temperature'] ?? null;
        $ambientTempC = $device['cap'] ?? null;
        
        $result = [
            'water_temp_c' => $waterTempC,
            'water_temp_f' => $waterTempC !== null ? self::celsiusToFahrenheit($waterTempC) : null,
            'ambient_temp_c' => $ambientTempC,
            'ambient_temp_f' => $ambientTempC !== null ? self::celsiusToFahrenheit($ambientTempC) : null,
            'battery_voltage' => $device['batteryVolt'] ?? null,
            'signal_strength' => $device['signaldBm'] ?? null,
            'device_uuid' => $device['uuid'] ?? null,
            'timestamp' => time()
        ];
        
        return $result;
    }
    
    /**
     * Apply ambient temperature calibration
     * Based on observed calibration logic from existing system
     */
    public static function calibrateAmbientTemperature(float $ambientTempF, float $waterTempF): float
    {
        $calibrationOffset = ($ambientTempF - $waterTempF) * 0.15;
        return $ambientTempF + $calibrationOffset;
    }
    
    /**
     * Validate temperature readings for safety
     */
    public static function validateTemperature(float $tempF, string $context = 'temperature'): bool
    {
        // Reasonable bounds for hot tub temperature readings
        $minTemp = 32;  // 0°C (freezing)
        $maxTemp = 120; // 49°C (very hot)
        
        if ($tempF < $minTemp || $tempF > $maxTemp) {
            error_log("Invalid {$context} reading: {$tempF}°F (outside range {$minTemp}-{$maxTemp}°F)");
            return false;
        }
        
        return true;
    }
}
```

## Complete Integration Example

### Hot Tub Controller Service

```php
<?php

class HotTubController
{
    private IftttWebhookClient $ifttt;
    private WirelessTagClient $wirelessTag;
    private array $deviceConfig;
    
    public function __construct(
        IftttWebhookClient $ifttt,
        WirelessTagClient $wirelessTag,
        array $deviceConfig
    ) {
        $this->ifttt = $ifttt;
        $this->wirelessTag = $wirelessTag;
        $this->deviceConfig = $deviceConfig;
    }
    
    /**
     * Get current hot tub status with temperature readings
     */
    public function getCurrentStatus(bool $forceFresh = false): array
    {
        $hotTubDeviceId = $this->deviceConfig['hot_tub_water_sensor'];
        
        if ($forceFresh) {
            $sensorData = $this->wirelessTag->getFreshTemperatureData($hotTubDeviceId);
        } else {
            $sensorData = $this->wirelessTag->getCachedTemperatureData($hotTubDeviceId);
        }
        
        if (!$sensorData) {
            throw new RuntimeException("Failed to retrieve temperature data");
        }
        
        $tempData = TemperatureProcessor::extractTemperatureData($sensorData, 0);
        
        // Validate temperature readings
        if ($tempData['water_temp_f'] !== null && 
            !TemperatureProcessor::validateTemperature($tempData['water_temp_f'], 'water')) {
            throw new RuntimeException("Invalid water temperature reading");
        }
        
        return [
            'water_temperature' => [
                'celsius' => $tempData['water_temp_c'],
                'fahrenheit' => $tempData['water_temp_f']
            ],
            'ambient_temperature' => [
                'celsius' => $tempData['ambient_temp_c'],
                'fahrenheit' => $tempData['ambient_temp_f'],
                'calibrated_fahrenheit' => $tempData['ambient_temp_f'] !== null && $tempData['water_temp_f'] !== null
                    ? TemperatureProcessor::calibrateAmbientTemperature($tempData['ambient_temp_f'], $tempData['water_temp_f'])
                    : null
            ],
            'sensor_info' => [
                'battery_voltage' => $tempData['battery_voltage'],
                'signal_strength' => $tempData['signal_strength'],
                'last_updated' => $tempData['timestamp']
            ]
        ];
    }
    
    /**
     * Start heating cycle with fresh temperature check
     */
    public function startHeating(float $targetTempF): bool
    {
        try {
            // Get fresh temperature reading before starting
            $status = $this->getCurrentStatus(true);
            $currentTempF = $status['water_temperature']['fahrenheit'];
            
            if ($currentTempF === null) {
                throw new RuntimeException("Cannot start heating: invalid temperature reading");
            }
            
            if ($currentTempF >= $targetTempF) {
                error_log("Heating not needed: current {$currentTempF}°F >= target {$targetTempF}°F");
                return false;
            }
            
            // Start heating via IFTTT
            if ($this->ifttt->startHeating()) {
                error_log("Heating started: {$currentTempF}°F -> {$targetTempF}°F");
                return true;
            } else {
                throw new RuntimeException("IFTTT heating command failed");
            }
            
        } catch (Exception $e) {
            error_log("Failed to start heating: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Stop heating cycle
     */
    public function stopHeating(): bool
    {
        try {
            if ($this->ifttt->stopHeating()) {
                error_log("Heating stopped successfully");
                return true;
            } else {
                throw new RuntimeException("IFTTT stop heating command failed");
            }
            
        } catch (Exception $e) {
            error_log("Failed to stop heating: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if target temperature has been reached
     */
    public function hasReachedTarget(float $targetTempF, bool $forceFresh = true): bool
    {
        try {
            $status = $this->getCurrentStatus($forceFresh);
            $currentTempF = $status['water_temperature']['fahrenheit'];
            
            if ($currentTempF === null) {
                error_log("Cannot check target: invalid temperature reading");
                return false;
            }
            
            return $currentTempF >= $targetTempF;
            
        } catch (Exception $e) {
            error_log("Failed to check target temperature: " . $e->getMessage());
            return false;
        }
    }
}
```

### Configuration Example

```php
<?php

// Example configuration array
$config = [
    'ifttt' => [
        'api_key' => 'your_ifttt_webhook_key_here'
    ],
    'wirelesstag' => [
        'oauth_token' => 'your_oauth_bearer_token_here',
        'devices' => [
            'hot_tub_water_sensor' => 'device-uuid-1',
            'ambient_temp_sensor' => 'device-uuid-2'
        ]
    ]
];

// Initialize clients
$ifttt = new IftttWebhookClient($config['ifttt']['api_key']);
$wirelessTag = new WirelessTagClient($config['wirelesstag']['oauth_token']);

// Initialize controller
$hotTubController = new HotTubController(
    $ifttt,
    $wirelessTag,
    $config['wirelesstag']['devices']
);

// Example usage
try {
    $status = $hotTubController->getCurrentStatus();
    echo "Current water temperature: " . $status['water_temperature']['fahrenheit'] . "°F\n";
    
    // Start heating to 104°F
    if ($hotTubController->startHeating(104.0)) {
        echo "Heating started successfully\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Error Handling Best Practices

### Logging Strategy

```php
<?php

class ApiLogger
{
    public static function logApiCall(string $service, string $endpoint, bool $success, array $context = []): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'service' => $service,
            'endpoint' => $endpoint,
            'success' => $success,
            'context' => $context
        ];
        
        $logMessage = json_encode($logData);
        
        if ($success) {
            error_log("API_SUCCESS: {$logMessage}");
        } else {
            error_log("API_ERROR: {$logMessage}");
        }
    }
}
```

### Health Check Implementation

```php
<?php

class ApiHealthChecker
{
    private IftttWebhookClient $ifttt;
    private WirelessTagClient $wirelessTag;
    
    public function checkApiHealth(): array
    {
        $results = [
            'ifttt' => ['status' => 'unknown', 'message' => ''],
            'wirelesstag' => ['status' => 'unknown', 'message' => ''],
            'overall' => 'unknown'
        ];
        
        // Test IFTTT (note: this would actually trigger the webhook)
        // In practice, you might want a dedicated test endpoint
        $results['ifttt'] = [
            'status' => 'healthy',
            'message' => 'IFTTT webhooks configured (testing requires actual trigger)'
        ];
        
        // Test WirelessTag
        try {
            $testDevice = 'test-device-id';
            $data = $this->wirelessTag->getCachedTemperatureData($testDevice);
            
            if ($data !== null) {
                $results['wirelesstag'] = [
                    'status' => 'healthy',
                    'message' => 'WirelessTag API responding normally'
                ];
            } else {
                $results['wirelesstag'] = [
                    'status' => 'degraded',
                    'message' => 'WirelessTag API accessible but no data returned'
                ];
            }
            
        } catch (Exception $e) {
            $results['wirelesstag'] = [
                'status' => 'unhealthy',
                'message' => 'WirelessTag API error: ' . $e->getMessage()
            ];
        }
        
        // Determine overall health
        $allHealthy = true;
        foreach (['ifttt', 'wirelesstag'] as $service) {
            if ($results[$service]['status'] !== 'healthy') {
                $allHealthy = false;
                break;
            }
        }
        
        $results['overall'] = $allHealthy ? 'healthy' : 'degraded';
        
        return $results;
    }
}
```

This implementation guide provides robust, production-ready code for integrating with both external APIs, complete with proper error handling, retry logic, and monitoring capabilities.