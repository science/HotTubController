<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\LogRotationService;

/**
 * Controller for maintenance tasks like log rotation.
 *
 * Used by cron jobs to perform periodic maintenance operations.
 */
class MaintenanceController
{
    private const DAYS_TO_COMPRESS = 30;
    private const DAYS_TO_DELETE = 180; // 6 months

    public function __construct(
        private LogRotationService $logRotationService,
        private string $logsDirectory
    ) {}

    /**
     * Rotate log files: compress old logs, delete very old compressed logs.
     *
     * Policy:
     * - Compress .log files older than 30 days
     * - Delete .log/.log.gz files older than 6 months (180 days)
     *
     * @return array{status: int, body: array{timestamp: string, compressed: string[], deleted: string[]}}
     */
    public function rotateLogs(): array
    {
        $result = $this->logRotationService->rotate(
            $this->logsDirectory,
            '*.log*',
            self::DAYS_TO_COMPRESS,
            self::DAYS_TO_DELETE
        );

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'compressed' => $result['compressed'],
                'deleted' => $result['deleted'],
            ],
        ];
    }
}
