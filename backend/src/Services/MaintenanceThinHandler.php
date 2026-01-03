<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Thin handler for maintenance initialization.
 *
 * Bypasses the full framework to avoid ModSecurity blocking POST requests.
 * Uses simple token comparison (CRON_JWT) instead of full JWT validation.
 */
class MaintenanceThinHandler
{
    private string $envFile;
    private string $backendDir;

    public function __construct(string $envFile)
    {
        $this->envFile = $envFile;
        $this->backendDir = dirname($envFile);
    }

    /**
     * Handle maintenance init request.
     *
     * @param string|null $authHeader Authorization header value
     * @param string $method HTTP method
     * @return array{status: int, body: array}
     */
    public function handle(?string $authHeader, string $method = 'POST'): array
    {
        // Validate method
        if ($method !== 'POST') {
            return ['status' => 405, 'body' => ['error' => 'Method not allowed']];
        }

        // Validate authorization
        $expectedToken = $this->loadCronJwt();
        if ($expectedToken === null) {
            return ['status' => 500, 'body' => ['error' => 'Server configuration error: CRON_JWT not set']];
        }

        // Extract Bearer token
        $providedToken = null;
        if ($authHeader !== null && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $providedToken = $matches[1];
        }

        if ($providedToken === null || $providedToken !== $expectedToken) {
            return ['status' => 401, 'body' => ['error' => 'Invalid or missing authorization']];
        }

        // Run maintenance setup
        try {
            $result = $this->runMaintenanceSetup();
            return ['status' => 200, 'body' => $result];
        } catch (\Exception $e) {
            return ['status' => 500, 'body' => ['error' => 'Setup failed: ' . $e->getMessage()]];
        }
    }

    /**
     * Load CRON_JWT from .env file.
     */
    private function loadCronJwt(): ?string
    {
        if (!file_exists($this->envFile)) {
            return null;
        }

        $content = file_get_contents($this->envFile);
        if (preg_match('/^CRON_JWT=(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Run the maintenance setup (cron + healthcheck).
     */
    private function runMaintenanceSetup(): array
    {
        // Load required classes (order matters - interfaces before implementations)
        require_once $this->backendDir . '/src/Contracts/CrontabAdapterInterface.php';
        require_once $this->backendDir . '/src/Contracts/HealthchecksClientInterface.php';
        require_once $this->backendDir . '/src/Services/CrontabAdapter.php';
        require_once $this->backendDir . '/src/Services/CrontabBackupService.php';
        require_once $this->backendDir . '/src/Services/TimeConverter.php';
        require_once $this->backendDir . '/src/Services/NullHealthchecksClient.php';
        require_once $this->backendDir . '/src/Services/MaintenanceCronService.php';

        // Load config from .env
        $config = $this->loadConfig();

        // Create dependencies
        $crontabAdapter = new CrontabAdapter();
        $cronScriptPath = $this->backendDir . '/storage/bin/log-rotation-cron.sh';
        $healthcheckStateFile = $this->backendDir . '/storage/state/log-rotation-healthcheck.json';

        // Create healthchecks client (null if not configured)
        $healthchecksClient = new NullHealthchecksClient();

        // Try to create real client if configured
        $apiKey = $config['HEALTHCHECKS_IO_KEY'] ?? '';
        if (!empty($apiKey) && ($config['EXTERNAL_API_MODE'] ?? 'stub') === 'live') {
            require_once $this->backendDir . '/src/Services/HealthchecksClient.php';
            $channelId = $config['HEALTHCHECKS_IO_CHANNEL'] ?? null;
            $healthchecksClient = new HealthchecksClient($apiKey, $channelId);
        }

        // Create and run maintenance service
        $service = new MaintenanceCronService(
            $crontabAdapter,
            $cronScriptPath,
            $healthchecksClient,
            $healthcheckStateFile
        );

        $result = $service->ensureLogRotationCronExists();
        $pingUrl = $service->getHealthcheckPingUrl();

        return [
            'timestamp' => date('c'),
            'cron_created' => $result['created'],
            'cron_entry' => $result['entry'],
            'healthcheck_created' => $result['healthcheck'] !== null,
            'healthcheck_ping_url' => $pingUrl,
            'server_timezone' => $service->getServerTimezone(),
        ];
    }

    /**
     * Load configuration from .env file.
     */
    private function loadConfig(): array
    {
        $config = [];
        if (!file_exists($this->envFile)) {
            return $config;
        }

        $content = file_get_contents($this->envFile);
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                $config[$matches[1]] = trim($matches[2], '"\'');
            }
        }

        return $config;
    }
}
