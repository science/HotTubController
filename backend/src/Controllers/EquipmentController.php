<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\EventLogger;
use HotTub\Contracts\IftttClientInterface;

/**
 * Controller for hot tub equipment operations.
 *
 * All equipment control operations trigger IFTTT webhooks
 * and log events for audit purposes.
 */
class EquipmentController
{
    private EventLogger $logger;

    public function __construct(
        string $logFile,
        private IftttClientInterface $iftttClient
    ) {
        $this->logger = new EventLogger($logFile);
    }

    /**
     * Health check endpoint.
     *
     * Returns system status including IFTTT mode.
     */
    public function health(): array
    {
        return [
            'status' => 200,
            'body' => [
                'status' => 'ok',
                'ifttt_mode' => $this->iftttClient->getMode(),
            ],
        ];
    }

    /**
     * Turn on the heater.
     *
     * Triggers the IFTTT hot-tub-heat-on event which:
     * 1. Starts water circulation pump
     * 2. Waits for proper circulation
     * 3. Activates heating element
     */
    public function heaterOn(): array
    {
        $timestamp = date('c');
        $success = $this->iftttClient->trigger('hot-tub-heat-on');

        $this->logger->log('heater_on', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->iftttClient->getMode(),
        ]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'heater_on',
                'timestamp' => $timestamp,
            ],
        ];
    }

    /**
     * Turn off the heater.
     *
     * Triggers the IFTTT hot-tub-heat-off event which:
     * 1. Turns off heating element immediately
     * 2. Continues pump for heater cooling
     * 3. Stops pump after cooling period
     */
    public function heaterOff(): array
    {
        $timestamp = date('c');
        $success = $this->iftttClient->trigger('hot-tub-heat-off');

        $this->logger->log('heater_off', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->iftttClient->getMode(),
        ]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'heater_off',
                'timestamp' => $timestamp,
            ],
        ];
    }

    /**
     * Run the pump for 2 hours.
     *
     * Triggers the IFTTT cycle_hot_tub_ionizer event which
     * activates the circulation pump for 2 hours.
     */
    public function pumpRun(): array
    {
        $timestamp = date('c');
        $duration = 7200; // 2 hours in seconds
        $success = $this->iftttClient->trigger('cycle_hot_tub_ionizer');

        $this->logger->log('pump_run', [
            'duration' => $duration,
            'ifttt_success' => $success,
            'ifttt_mode' => $this->iftttClient->getMode(),
        ]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'pump_run',
                'duration' => $duration,
                'timestamp' => $timestamp,
            ],
        ];
    }
}
