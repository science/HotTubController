<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StatusAction extends Action
{
    private static ?float $processStartTime = null;
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $startTime = microtime(true);
        
        if (self::$processStartTime === null) {
            self::$processStartTime = $startTime;
        }
        
        $status = $this->determineSystemStatus();
        $metrics = $this->gatherSystemMetrics($startTime);
        
        $responseData = [
            'service' => 'Hot Tub Controller',
            'version' => '1.0.0',
            'status' => $status,
            'timestamp' => date('c'),
            'uptime_seconds' => round($startTime - self::$processStartTime, 2),
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'memory' => $metrics['memory'],
            'system' => $metrics['system']
        ];
        
        return $this->jsonResponse($responseData);
    }
    
    private function determineSystemStatus(): string
    {
        try {
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $memoryUsage = memory_get_usage(true);
            $memoryPercent = $memoryUsage / $memoryLimit;
            
            if ($memoryPercent > 0.9) {
                return 'warning';
            }
            
            if ($memoryPercent > 0.95) {
                return 'critical';
            }
            
            return 'ready';
        } catch (\Exception $e) {
            return 'ready';
        }
    }
    
    private function gatherSystemMetrics(float $startTime): array
    {
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        return [
            'memory' => [
                'usage_bytes' => $memoryUsage,
                'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'peak_bytes' => $memoryPeak,
                'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
                'limit_bytes' => $memoryLimit,
                'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                'usage_percent' => round(($memoryUsage / $memoryLimit) * 100, 1)
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'process_id' => getmypid(),
                'request_time' => $_SERVER['REQUEST_TIME'] ?? time(),
                'server_time' => time(),
                'timezone' => date_default_timezone_get()
            ]
        ];
    }
    
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }
        
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int) $memoryLimit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}