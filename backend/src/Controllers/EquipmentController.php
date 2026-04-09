<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\EventLogger;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\HeaterControlService;
use HotTub\Services\TargetTemperatureService;

/**
 * Controller for hot tub equipment operations.
 *
 * All equipment control operations go through HeaterControlService for
 * consistent IFTTT triggering, success checking, and status updates.
 * This controller adds API response formatting, request-level logging,
 * and heat-to-target cancellation on manual heater-off.
 */
class EquipmentController
{
    private EventLogger $logger;

    public function __construct(
        string $logFile,
        private HeaterControlService $heaterControl,
        private ?EquipmentStatusService $statusService = null,
        private ?TargetTemperatureService $targetTempService = null
    ) {
        $this->logger = new EventLogger($logFile);
    }

    /**
     * Health check endpoint.
     *
     * Returns system status including IFTTT mode and equipment status.
     */
    public function health(): array
    {
        $body = [
            'status' => 'ok',
            'ifttt_mode' => $this->heaterControl->getMode(),
        ];

        if ($this->statusService !== null) {
            $body['equipmentStatus'] = $this->statusService->getStatus();
        }

        return [
            'status' => 200,
            'body' => $body,
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
        $success = $this->heaterControl->heaterOn();

        $this->logger->log('heater_on', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->heaterControl->getMode(),
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
     * 2. Stops pump (hardware controller handles this)
     * 3. Cancels any active heat-to-target automation
     *
     * Note: The hardware controller turns off both the heater and pump
     * when this command is triggered, so we update both statuses.
     *
     * IMPORTANT: Manual heater off cancels heat-to-target to prevent the
     * confusing UX where the heater turns back on 60 seconds later.
     * Manual user action should override automation.
     */
    public function heaterOff(?string $source = null): array
    {
        $timestamp = date('c');

        // Watchdog: check equipment state before issuing heater-off
        // so we can log whether the heater was unexpectedly still on.
        if ($source === 'watchdog') {
            $heaterWasOn = $this->statusService?->getStatus()['heater']['on'] ?? false;
            $this->logger->log('watchdog_heater_off', [
                'heater_was_on' => $heaterWasOn,
            ]);
        }

        $success = $this->heaterControl->heaterOff();

        // Cancel heat-to-target if active (manual action overrides automation)
        $heatToTargetCanceled = false;
        if ($success && $this->targetTempService !== null) {
            $state = $this->targetTempService->getState();
            if ($state['active']) {
                $this->targetTempService->stop();
                $heatToTargetCanceled = true;
            }
        }

        $this->logger->log('heater_off', [
            'ifttt_success' => $success,
            'ifttt_mode' => $this->heaterControl->getMode(),
            'heat_to_target_canceled' => $heatToTargetCanceled,
        ]);

        $body = [
            'success' => $success,
            'action' => 'heater_off',
            'timestamp' => $timestamp,
            'heat_to_target_canceled' => $heatToTargetCanceled,
        ];

        if ($source !== null) {
            $body['source'] = $source;
        }

        return [
            'status' => $success ? 200 : 500,
            'body' => $body,
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
        $success = $this->heaterControl->pumpRun();

        $this->logger->log('pump_run', [
            'duration' => $duration,
            'ifttt_success' => $success,
            'ifttt_mode' => $this->heaterControl->getMode(),
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
