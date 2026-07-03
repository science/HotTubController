<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\IftttClientInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Factory for iot-api clients. Mirrors IftttClientFactory: mode resolution
 * priority is EXTERNAL_API_MODE env var, then config, then fail-safe stub.
 * Config keys: IOT_API_URL, IOT_API_JWT.
 */
class IotApiClientFactory
{
    /** @var array<string, string|null> */
    private array $config;
    private string $logFile;
    /** @var resource */
    private $output;

    /**
     * @param array<string, string|null> $config Environment configuration
     * @param string $logFile Path to event log file
     * @param resource|null $output Console output stream
     */
    public function __construct(array $config, string $logFile, $output = null)
    {
        $this->config = $config;
        $this->logFile = $logFile;
        $this->output = $output ?? fopen('php://stderr', 'w');
    }

    public function create(string $mode = 'auto'): IftttClientInterface
    {
        $resolvedMode = match ($mode) {
            'stub' => 'stub',
            'live' => 'live',
            'auto' => $this->resolveAutoMode(),
            default => throw new InvalidArgumentException("Invalid mode: {$mode}"),
        };

        if ($resolvedMode === 'live') {
            $url = trim((string) ($this->config['IOT_API_URL'] ?? ''));
            $jwt = trim((string) ($this->config['IOT_API_JWT'] ?? ''));
            if ($url === '' || $jwt === '') {
                throw new RuntimeException('IOT_API_URL and IOT_API_JWT required for live mode');
            }
            $httpClient = new CurlJsonHttpClient();
        } else {
            $url = trim((string) ($this->config['IOT_API_URL'] ?? '')) ?: 'https://stub.invalid/api/v1/command';
            $jwt = 'stub-mode-no-jwt';
            $httpClient = new StubJsonHttpClient();
        }

        $client = new IotApiClient(
            $url,
            $jwt,
            $httpClient,
            new ConsoleLogger($this->output),
            new EventLogger($this->logFile)
        );

        $this->getConsoleLogger()->init($client->getMode());

        return $client;
    }

    private function resolveAutoMode(): string
    {
        $envMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? null);
        if ($envMode !== null && $envMode !== '' && in_array($envMode, ['stub', 'live'], true)) {
            return $envMode;
        }

        $externalApiMode = $this->config['EXTERNAL_API_MODE'] ?? null;
        if ($externalApiMode !== null && in_array($externalApiMode, ['stub', 'live'], true)) {
            return $externalApiMode;
        }

        return 'stub';
    }

    private function getConsoleLogger(): ConsoleLogger
    {
        return new ConsoleLogger($this->output);
    }
}
