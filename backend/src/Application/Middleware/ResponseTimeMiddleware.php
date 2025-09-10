<?php

declare(strict_types=1);

namespace HotTubController\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResponseTimeMiddleware implements MiddlewareInterface
{
    private bool $enableLogging;
    private int $slowThresholdMs;
    
    public function __construct(bool $enableLogging = true, int $slowThresholdMs = 1000)
    {
        $this->enableLogging = $enableLogging;
        $this->slowThresholdMs = $slowThresholdMs;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startTime = microtime(true);
        
        $response = $handler->handle($request);
        
        $endTime = microtime(true);
        $durationMs = round(($endTime - $startTime) * 1000, 2);
        
        $response = $response->withHeader('X-Response-Time', $durationMs . 'ms');
        
        if ($this->enableLogging) {
            $this->logRequestMetrics($request, $durationMs);
        }
        
        return $response;
    }
    
    private function logRequestMetrics(ServerRequestInterface $request, float $durationMs): void
    {
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();
        $userAgent = $request->getHeaderLine('User-Agent');
        
        $logData = [
            'method' => $method,
            'uri' => $uri,
            'duration_ms' => $durationMs,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'timestamp' => date('c')
        ];
        
        if ($durationMs >= $this->slowThresholdMs) {
            $this->logSlowRequest($logData, $userAgent);
        } else {
            $this->logNormalRequest($logData);
        }
    }
    
    private function logSlowRequest(array $logData, string $userAgent): void
    {
        error_log(sprintf(
            "SLOW REQUEST: %s %s took %sms (memory: %sMB) - User-Agent: %s",
            $logData['method'],
            $logData['uri'],
            $logData['duration_ms'],
            $logData['memory_usage_mb'],
            $userAgent ?: 'Unknown'
        ));
        
        $this->writePerformanceLog('slow', $logData);
    }
    
    private function logNormalRequest(array $logData): void
    {
        if ($this->isStatusEndpoint($logData['uri'])) {
            $this->writePerformanceLog('status', $logData);
        }
    }
    
    private function isStatusEndpoint(string $uri): bool
    {
        return in_array($uri, ['/', '/index.php', '/api/v1/status'], true);
    }
    
    private function writePerformanceLog(string $type, array $logData): void
    {
        $logFile = __DIR__ . '/../../../storage/logs/performance-' . $type . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logEntry = json_encode($logData) . "\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        $this->rotateLogIfNeeded($logFile);
    }
    
    private function rotateLogIfNeeded(string $logFile): void
    {
        if (!file_exists($logFile)) {
            return;
        }
        
        $maxSize = 10 * 1024 * 1024;
        
        if (filesize($logFile) > $maxSize) {
            $rotatedFile = $logFile . '.' . date('Y-m-d-H-i-s');
            rename($logFile, $rotatedFile);
            
            if (function_exists('gzencode')) {
                $content = file_get_contents($rotatedFile);
                file_put_contents($rotatedFile . '.gz', gzencode($content));
                unlink($rotatedFile);
            }
        }
    }
}