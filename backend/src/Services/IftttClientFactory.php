<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\IftttClientInterface;
use HotTub\Contracts\HttpClientInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Factory for creating IFTTT clients based on environment configuration.
 *
 * This factory uses the Strategy pattern with late-binding HTTP clients:
 * - All business logic is handled by the unified IftttClient
 * - Only the HTTP client differs between modes (StubHttpClient vs CurlHttpClient)
 * - The branching happens at the lowest possible level (actual network call)
 *
 * Mode resolution:
 * - 'stub': Uses StubHttpClient (simulated responses, no network calls)
 * - 'live': Uses CurlHttpClient (real IFTTT API calls)
 * - 'auto': Checks environment and API key availability
 */
class IftttClientFactory
{
    /** @var array<string, string|null> */
    private array $config;
    private string $logFile;
    /** @var resource */
    private $output;

    /**
     * @param array<string, string|null> $config Environment configuration
     * @param string $logFile Path to event log file
     * @param resource|null $output Console output stream (uses php://stderr if not provided)
     */
    public function __construct(array $config, string $logFile, $output = null)
    {
        $this->config = $config;
        $this->logFile = $logFile;
        // Use php://stderr instead of STDERR constant - the constant is only
        // available in CLI mode and causes "Undefined constant" errors in web server mode
        $this->output = $output ?? fopen('php://stderr', 'w');
    }

    /**
     * Create an IFTTT client based on the specified mode.
     *
     * Both stub and live modes use the same unified IftttClient class.
     * The only difference is which HTTP client is injected:
     * - stub: StubHttpClient (simulates network calls)
     * - live: CurlHttpClient (makes real network calls)
     *
     * @param string $mode 'stub', 'live', or 'auto'
     * @return IftttClientInterface
     * @throws RuntimeException If live mode is requested without API key
     * @throws InvalidArgumentException If an invalid mode is specified
     */
    public function create(string $mode = 'auto'): IftttClientInterface
    {
        $resolvedMode = match ($mode) {
            'stub' => 'stub',
            'live' => 'live',
            'auto' => $this->resolveAutoMode(),
            default => throw new InvalidArgumentException("Invalid mode: {$mode}"),
        };

        $client = $this->createUnifiedClient($resolvedMode);

        // Log initialization
        $this->getConsoleLogger()->init($client->getMode());

        return $client;
    }

    /**
     * Create unified IFTTT client with appropriate HTTP client.
     *
     * This is the core of the late-binding strategy:
     * - Same IftttClient class handles all business logic
     * - HTTP client determines whether actual network calls are made
     */
    private function createUnifiedClient(string $mode): IftttClient
    {
        if ($mode === 'live') {
            $apiKey = $this->getApiKey();
            if (empty($apiKey)) {
                throw new RuntimeException('IFTTT_WEBHOOK_KEY required for live mode');
            }
            $httpClient = new CurlHttpClient();
        } else {
            // For stub mode, we still need an API key for URL building (can be placeholder)
            $apiKey = $this->getApiKey() ?? 'stub-mode-no-key';
            $httpClient = new StubHttpClient();
        }

        return new IftttClient(
            $apiKey,
            $httpClient,
            $this->getConsoleLogger(),
            $this->getEventLogger()
        );
    }

    /**
     * Resolve auto mode to either stub or live.
     *
     * Priority:
     * 1. EXTERNAL_API_MODE from environment variable (allows test override via phpunit.xml)
     * 2. EXTERNAL_API_MODE from config (unified system mode from .env file)
     * 3. IFTTT_MODE from config (legacy fallback)
     * 4. Default to 'stub' (fail-safe)
     */
    private function resolveAutoMode(): string
    {
        // Priority 1: Check environment variable (for test isolation via phpunit.xml)
        $envMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? null);
        if ($envMode !== null && $envMode !== '' && in_array($envMode, ['stub', 'live'], true)) {
            return $envMode;
        }

        // Priority 2: Check EXTERNAL_API_MODE from config (unified system mode)
        $externalApiMode = $this->config['EXTERNAL_API_MODE'] ?? null;
        if ($externalApiMode !== null && in_array($externalApiMode, ['stub', 'live'], true)) {
            return $externalApiMode;
        }

        // Priority 3: Check legacy IFTTT_MODE
        $iftttMode = $this->config['IFTTT_MODE'] ?? null;
        if ($iftttMode !== null && in_array($iftttMode, ['stub', 'live'], true)) {
            return $iftttMode;
        }

        // Priority 4: Default to stub (fail-safe)
        return 'stub';
    }

    private function isTestingEnvironment(): bool
    {
        $env = $this->config['APP_ENV'] ?? 'development';
        return in_array($env, ['testing', 'test'], true);
    }

    private function hasApiKey(): bool
    {
        return !empty($this->getApiKey());
    }

    private function getApiKey(): ?string
    {
        return $this->config['IFTTT_WEBHOOK_KEY'] ?? null;
    }

    private function getConsoleLogger(): ConsoleLogger
    {
        return new ConsoleLogger($this->output);
    }

    private function getEventLogger(): EventLogger
    {
        return new EventLogger($this->logFile);
    }
}
