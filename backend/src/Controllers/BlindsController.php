<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\EventLogger;
use HotTub\Contracts\IftttClientInterface;

/**
 * Controller for dining room blinds control.
 *
 * This is an isolated/optional feature that only works when
 * BLINDS_FEATURE_ENABLED is set to 'true' in the environment config.
 *
 * Triggers IFTTT webhooks for SmartLife-controlled blinds.
 */
class BlindsController
{
    private EventLogger $logger;
    private bool $enabled;

    /**
     * @param string $logFile Path to event log file
     * @param IftttClientInterface $iftttClient IFTTT client for triggering webhooks
     * @param array<string, string> $config Environment configuration
     */
    public function __construct(
        string $logFile,
        private IftttClientInterface $iftttClient,
        array $config = []
    ) {
        $this->logger = new EventLogger($logFile);
        $this->enabled = ($config['BLINDS_FEATURE_ENABLED'] ?? 'false') === 'true';
    }

    /**
     * Check if the blinds feature is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Open the dining room blinds.
     *
     * Triggers the IFTTT open-dining-room-blinds webhook.
     */
    public function open(): array
    {
        if (!$this->enabled) {
            return [
                'status' => 404,
                'body' => ['error' => 'Blinds feature not enabled'],
            ];
        }

        $timestamp = date('c');
        $success = $this->iftttClient->trigger('open-dining-room-blinds');

        $this->logger->log('blinds_open', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->iftttClient->getMode(),
        ]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'blinds_open',
                'timestamp' => $timestamp,
            ],
        ];
    }

    /**
     * Close the dining room blinds.
     *
     * Triggers the IFTTT close-dining-room-blinds webhook.
     */
    public function close(): array
    {
        if (!$this->enabled) {
            return [
                'status' => 404,
                'body' => ['error' => 'Blinds feature not enabled'],
            ];
        }

        $timestamp = date('c');
        $success = $this->iftttClient->trigger('close-dining-room-blinds');

        $this->logger->log('blinds_close', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->iftttClient->getMode(),
        ]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'blinds_close',
                'timestamp' => $timestamp,
            ],
        ];
    }
}
