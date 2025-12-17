<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Services\LogRotationService;

/**
 * Controller for maintenance tasks like log rotation.
 *
 * Used by cron jobs to perform periodic maintenance operations.
 * Integrates with Healthchecks.io to signal successful completion.
 */
class MaintenanceController
{
    private const DAYS_TO_COMPRESS = 30;
    private const DAYS_TO_DELETE = 180; // 6 months

    public function __construct(
        private LogRotationService $logRotationService,
        private string $logsDirectory,
        private ?HealthchecksClientInterface $healthchecksClient = null,
        private ?string $healthcheckPingUrl = null
    ) {}

    /**
     * Rotate log files: compress old logs, delete very old compressed logs.
     *
     * Policy:
     * - Compress .log files older than 30 days
     * - Delete .log/.log.gz files older than 6 months (180 days)
     *
     * On success, pings Healthchecks.io to signal the job completed.
     *
     * @return array{status: int, body: array{timestamp: string, compressed: string[], deleted: string[], healthcheck_pinged: bool}}
     */
    public function rotateLogs(): array
    {
        $result = $this->logRotationService->rotate(
            $this->logsDirectory,
            '*.log*',
            self::DAYS_TO_COMPRESS,
            self::DAYS_TO_DELETE
        );

        // Ping Healthchecks.io on successful completion
        $healthcheckPinged = $this->pingHealthcheck();

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'compressed' => $result['compressed'],
                'deleted' => $result['deleted'],
                'healthcheck_pinged' => $healthcheckPinged,
            ],
        ];
    }

    /**
     * Ping Healthchecks.io to signal successful log rotation.
     *
     * @return bool True if pinged successfully, false otherwise
     */
    private function pingHealthcheck(): bool
    {
        if ($this->healthcheckPingUrl === null) {
            return false;
        }

        if ($this->healthchecksClient === null || !$this->healthchecksClient->isEnabled()) {
            return false;
        }

        return $this->healthchecksClient->ping($this->healthcheckPingUrl);
    }
}
