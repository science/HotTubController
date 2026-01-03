<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\MaintenanceCronService;

/**
 * Controller for setting up maintenance infrastructure.
 *
 * Called by the deploy workflow after FTP upload to:
 * - Create the log rotation cron job (if not exists)
 * - Create Healthchecks.io monitoring check (if not exists)
 * - Ping the healthcheck to arm it
 *
 * This enables zero-click deploys where GitHub Actions can fully
 * configure the server without manual SSH intervention.
 */
class MaintenanceSetupController
{
    public function __construct(
        private MaintenanceCronService $maintenanceCronService
    ) {}

    /**
     * Set up maintenance infrastructure (cron + healthcheck).
     *
     * This is idempotent - safe to call on every deploy.
     *
     * @return array{status: int, body: array}
     */
    public function setup(): array
    {
        $result = $this->maintenanceCronService->ensureLogRotationCronExists();

        // Get the ping URL (may be from new or existing healthcheck)
        $pingUrl = $this->maintenanceCronService->getHealthcheckPingUrl();

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'cron_created' => $result['created'],
                'cron_entry' => $result['entry'],
                'healthcheck_created' => $result['healthcheck'] !== null,
                'healthcheck_ping_url' => $pingUrl,
                'server_timezone' => $this->maintenanceCronService->getServerTimezone(),
            ],
        ];
    }
}
