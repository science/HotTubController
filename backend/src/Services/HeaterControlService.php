<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;

/**
 * Unified hardware control: single point for all IFTTT triggers.
 *
 * Every heater/pump on/off action goes through this service, ensuring
 * consistent IFTTT triggering, success checking, and equipment status updates.
 * Also handles watchdog cron cleanup on heater-on events.
 */
class HeaterControlService
{
    public function __construct(
        private IftttClientInterface $iftttClient,
        private EquipmentStatusService $statusService,
        private ?CrontabAdapterInterface $crontabAdapter = null,
        private ?string $jobsDir = null,
    ) {}

    public function heaterOn(): bool
    {
        // Clear any existing watchdog crons — a new heater-on event means
        // either a new session (which will schedule its own watchdog) or a
        // manual action (which shouldn't be fought by a stale watchdog).
        $this->cleanupWatchdogCrons();

        $success = $this->iftttClient->trigger('hot-tub-heat-on');
        if ($success) {
            $this->statusService->setHeaterOn();
        }
        return $success;
    }

    private function cleanupWatchdogCrons(): void
    {
        if ($this->crontabAdapter !== null) {
            TargetTemperatureService::cleanupWatchdogCrons($this->crontabAdapter, $this->jobsDir);
        }
    }

    public function heaterOff(): bool
    {
        $success = $this->iftttClient->trigger('hot-tub-heat-off');
        if ($success) {
            $this->statusService->setHeaterOff();
            $this->statusService->setPumpOff();
        }
        return $success;
    }

    public function pumpRun(): bool
    {
        $success = $this->iftttClient->trigger('cycle_hot_tub_ionizer');
        if ($success) {
            $this->statusService->setPumpOn();
        }
        return $success;
    }

    public function getMode(): string
    {
        return $this->iftttClient->getMode();
    }
}
