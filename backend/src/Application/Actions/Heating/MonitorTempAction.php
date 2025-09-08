<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\Action;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Services\CronManager;
use HotTubController\Services\CronSecurityManager;
use HotTubController\Services\IftttWebhookClient;
use HotTubController\Services\WirelessTagClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use RuntimeException;

/**
 * MonitorTempAction - Temperature monitoring during active heating cycles
 * 
 * This endpoint is called by self-deleting monitoring cron jobs to check
 * the current temperature and decide whether to continue heating, schedule
 * the next check, or complete the heating cycle when target is reached.
 */
class MonitorTempAction extends Action
{
    private WirelessTagClient $wirelessTagClient;
    private IftttWebhookClient $iftttClient;
    private CronManager $cronManager;
    private CronSecurityManager $securityManager;
    private CronJobBuilder $cronJobBuilder;
    private HeatingCycleRepository $cycleRepository;
    
    private const MAX_HEATING_DURATION_HOURS = 4;
    private const PRECISION_MODE_THRESHOLD = 2.0; // degrees
    private const TARGET_TOLERANCE = 0.5; // degrees
    private const MAX_TEMPERATURE_SAFETY = 110.0; // degrees
    
    public function __construct(
        LoggerInterface $logger,
        WirelessTagClient $wirelessTagClient,
        IftttWebhookClient $iftttClient,
        CronManager $cronManager,
        CronSecurityManager $securityManager,
        CronJobBuilder $cronJobBuilder,
        HeatingCycleRepository $cycleRepository
    ) {
        parent::__construct($logger);
        $this->wirelessTagClient = $wirelessTagClient;
        $this->iftttClient = $iftttClient;
        $this->cronManager = $cronManager;
        $this->securityManager = $securityManager;
        $this->cronJobBuilder = $cronJobBuilder;
        $this->cycleRepository = $cycleRepository;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Parse request parameters
        $input = $this->getJsonInput($request);
        $cycleId = $input['cycle_id'] ?? null;
        $monitorId = $input['monitor_id'] ?? null;
        $checkTime = $input['check_time'] ?? null;
        
        $this->logger->info('Monitoring temperature for heating cycle', [
            'cycle_id' => $cycleId,
            'monitor_id' => $monitorId,
            'check_time' => $checkTime,
        ]);
        
        try {
            // Authenticate cron API key
            $this->authenticateCronRequest($request);
            
            // Validate parameters
            $this->validateMonitorParameters($cycleId, $monitorId);
            
            // Load heating cycle
            $cycle = $this->loadHeatingCycle($cycleId);
            
            // Check for safety timeouts
            $this->checkSafetyLimits($cycle);
            
            // Get current temperature
            $currentTemp = $this->getCurrentTemperature();
            
            // Update cycle with current temperature
            $cycle->setCurrentTemp($currentTemp);
            $cycle->setLastTempCheck(new DateTime());
            
            // Determine next action based on temperature
            $decision = $this->makeHeatingDecision($cycle, $currentTemp);
            
            switch ($decision['action']) {
                case 'complete':
                    return $this->completeHeating($cycle, $currentTemp, $decision);
                    
                case 'continue':
                    return $this->continueHeating($cycle, $currentTemp, $decision);
                    
                case 'emergency_stop':
                    return $this->emergencyStop($cycle, $currentTemp, $decision);
                    
                default:
                    throw new RuntimeException('Unknown heating decision action: ' . $decision['action']);
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to monitor temperature', [
                'cycle_id' => $cycleId,
                'monitor_id' => $monitorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse('Temperature monitoring failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Authenticate the cron API request
     */
    private function authenticateCronRequest(ServerRequestInterface $request): void
    {
        $input = $this->getJsonInput($request);
        $providedAuth = $input['auth'] ?? null;
        
        if (empty($providedAuth)) {
            throw new RuntimeException('Missing authentication parameter');
        }
        
        if (!$this->securityManager->verifyApiKey($providedAuth)) {
            $this->logger->warning('Invalid cron API key provided for monitoring');
            throw new RuntimeException('Invalid authentication key');
        }
    }
    
    /**
     * Validate monitoring parameters
     */
    private function validateMonitorParameters(string $cycleId, string $monitorId): void
    {
        if (empty($cycleId) || empty($monitorId)) {
            throw new RuntimeException('Missing cycle_id or monitor_id parameter');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $cycleId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $monitorId)) {
            throw new RuntimeException('Invalid ID format');
        }
    }
    
    /**
     * Load the heating cycle being monitored
     */
    private function loadHeatingCycle(string $cycleId): HeatingCycle
    {
        $cycle = $this->cycleRepository->findById($cycleId);
        
        if (!$cycle) {
            throw new RuntimeException("Heating cycle not found: {$cycleId}");
        }
        
        if ($cycle->getStatus() !== HeatingCycle::STATUS_HEATING) {
            throw new RuntimeException("Cycle is not in heating status: " . $cycle->getStatus());
        }
        
        return $cycle;
    }
    
    /**
     * Check safety limits and timeouts
     */
    private function checkSafetyLimits(HeatingCycle $cycle): void
    {
        // Check maximum heating duration
        $startTime = $cycle->getStartedAt();
        $now = new DateTime();
        $heatingDuration = $now->getTimestamp() - $startTime->getTimestamp();
        $maxDurationSeconds = self::MAX_HEATING_DURATION_HOURS * 3600;
        
        if ($heatingDuration > $maxDurationSeconds) {
            throw new RuntimeException(
                'Maximum heating duration exceeded: ' . 
                round($heatingDuration / 3600, 1) . ' hours'
            );
        }
        
        // Check if cycle has been orphaned (no temperature checks in too long)
        $lastCheck = $cycle->getLastTempCheck();
        if ($lastCheck) {
            $timeSinceLastCheck = $now->getTimestamp() - $lastCheck->getTimestamp();
            if ($timeSinceLastCheck > 3600) { // 1 hour without check
                throw new RuntimeException(
                    'Heating cycle appears orphaned: no checks for ' . 
                    round($timeSinceLastCheck / 60) . ' minutes'
                );
            }
        }
    }
    
    /**
     * Get current water temperature
     */
    private function getCurrentTemperature(): float
    {
        $this->logger->info('Getting current temperature for monitoring');
        
        // For monitoring, we can use cached readings most of the time
        // Only request fresh reading if we're close to target (precision mode)
        $temperatureData = $this->wirelessTagClient->getTemperatureData();
        
        if (empty($temperatureData)) {
            throw new RuntimeException('No temperature data available from sensors');
        }
        
        $primarySensor = reset($temperatureData);
        $currentTemp = $primarySensor['temperature'];
        
        $this->logger->info('Current temperature for monitoring', [
            'temperature' => $currentTemp,
            'sensor_name' => $primarySensor['name'] ?? 'Unknown',
        ]);
        
        return $currentTemp;
    }
    
    /**
     * Make decision about heating continuation
     */
    private function makeHeatingDecision(HeatingCycle $cycle, float $currentTemp): array
    {
        $targetTemp = $cycle->getTargetTemp();
        $tempDifference = $targetTemp - $currentTemp;
        
        // Safety check: temperature too high
        if ($currentTemp >= self::MAX_TEMPERATURE_SAFETY) {
            return [
                'action' => 'emergency_stop',
                'reason' => 'temperature_safety_limit',
                'message' => "Temperature {$currentTemp}°F exceeds safety limit",
            ];
        }
        
        // Target reached (within tolerance)
        if (abs($tempDifference) <= self::TARGET_TOLERANCE) {
            return [
                'action' => 'complete',
                'reason' => 'target_reached',
                'message' => "Target temperature reached: {$currentTemp}°F",
            ];
        }
        
        // Temperature overshot (rare, but possible)
        if ($currentTemp > $targetTemp) {
            return [
                'action' => 'complete',
                'reason' => 'target_exceeded',
                'message' => "Temperature exceeded target: {$currentTemp}°F > {$targetTemp}°F",
            ];
        }
        
        // Continue heating
        $precisionMode = $tempDifference <= self::PRECISION_MODE_THRESHOLD;
        $nextCheckTime = $this->cronJobBuilder->calculateNextCheckTime(
            $currentTemp,
            $targetTemp,
            new DateTime(),
            $precisionMode
        );
        
        return [
            'action' => 'continue',
            'reason' => 'target_not_reached',
            'message' => "Continuing heating: {$currentTemp}°F → {$targetTemp}°F",
            'next_check_time' => $nextCheckTime,
            'precision_mode' => $precisionMode,
            'temp_difference' => $tempDifference,
        ];
    }
    
    /**
     * Complete heating cycle (target reached)
     */
    private function completeHeating(HeatingCycle $cycle, float $currentTemp, array $decision): Response
    {
        $this->logger->info('Completing heating cycle', [
            'cycle_id' => $cycle->getId(),
            'final_temp' => $currentTemp,
            'target_temp' => $cycle->getTargetTemp(),
            'reason' => $decision['reason'],
        ]);
        
        // Stop heating equipment
        $this->stopHeatingEquipment();
        
        // Update cycle status
        $cycle->setStatus(HeatingCycle::STATUS_COMPLETED);
        $cycle->setCompletedAt(new DateTime());
        $cycle->setFinalTemp($currentTemp);
        $this->cycleRepository->save($cycle);
        
        // Clean up any remaining monitoring crons for this cycle
        $this->cronManager->removeMonitoringEvents($cycle->getId());
        
        $this->logger->info('Heating cycle completed successfully', [
            'cycle_id' => $cycle->getId(),
            'duration_minutes' => $this->calculateCycleDuration($cycle),
        ]);
        
        return $this->jsonResponse([
            'status' => 'heating_completed',
            'cycle_id' => $cycle->getId(),
            'current_temp' => $currentTemp,
            'target_temp' => $cycle->getTargetTemp(),
            'completion_reason' => $decision['reason'],
            'message' => $decision['message'],
            'duration_minutes' => $this->calculateCycleDuration($cycle),
        ]);
    }
    
    /**
     * Continue heating (schedule next check)
     */
    private function continueHeating(HeatingCycle $cycle, float $currentTemp, array $decision): Response
    {
        $nextCheckTime = $decision['next_check_time'];
        $monitorId = 'monitor-' . $cycle->getId() . '-' . time();
        
        $this->logger->info('Continuing heating cycle', [
            'cycle_id' => $cycle->getId(),
            'current_temp' => $currentTemp,
            'target_temp' => $cycle->getTargetTemp(),
            'next_check' => $nextCheckTime->format('Y-m-d H:i:s'),
            'precision_mode' => $decision['precision_mode'],
        ]);
        
        // Schedule next monitoring check
        $cronConfig = $this->cronJobBuilder->buildMonitorTempCron(
            $nextCheckTime,
            $cycle->getId(),
            $monitorId
        );
        
        $cronId = $this->cronManager->addMonitoringEvent(
            $nextCheckTime,
            $monitorId,
            $cronConfig['config_file']
        );
        
        // Update cycle record
        $this->cycleRepository->save($cycle);
        
        // Estimate time remaining
        $timeRemainingMinutes = $this->cronJobBuilder->calculateHeatingTime(
            $currentTemp,
            $cycle->getTargetTemp()
        );
        
        return $this->jsonResponse([
            'status' => 'heating_continuing',
            'cycle_id' => $cycle->getId(),
            'current_temp' => $currentTemp,
            'target_temp' => $cycle->getTargetTemp(),
            'next_check' => $nextCheckTime->format('c'),
            'precision_mode' => $decision['precision_mode'],
            'temp_difference' => $decision['temp_difference'],
            'time_remaining_estimate_minutes' => $timeRemainingMinutes,
            'cron_id' => $cronId,
        ]);
    }
    
    /**
     * Emergency stop heating
     */
    private function emergencyStop(HeatingCycle $cycle, float $currentTemp, array $decision): Response
    {
        $this->logger->error('Emergency stop triggered', [
            'cycle_id' => $cycle->getId(),
            'current_temp' => $currentTemp,
            'reason' => $decision['reason'],
            'message' => $decision['message'],
        ]);
        
        // Stop heating equipment immediately
        $this->stopHeatingEquipment();
        
        // Update cycle status
        $cycle->setStatus(HeatingCycle::STATUS_ERROR);
        $cycle->setCompletedAt(new DateTime());
        $cycle->setFinalTemp($currentTemp);
        $cycle->addMetadata('error_reason', $decision['reason']);
        $cycle->addMetadata('error_message', $decision['message']);
        $this->cycleRepository->save($cycle);
        
        // Clean up all monitoring crons
        $this->cronManager->removeMonitoringEvents($cycle->getId());
        
        return $this->jsonResponse([
            'status' => 'emergency_stopped',
            'cycle_id' => $cycle->getId(),
            'current_temp' => $currentTemp,
            'reason' => $decision['reason'],
            'message' => $decision['message'],
        ], 500);
    }
    
    /**
     * Stop heating equipment via IFTTT
     */
    private function stopHeatingEquipment(): void
    {
        $this->logger->info('Stopping heating equipment');
        
        $result = $this->iftttClient->triggerEvent('hot-tub-heat-off');
        
        if (!$result['success']) {
            $this->logger->error('Failed to stop heating equipment', $result);
            throw new RuntimeException('Failed to stop heating equipment: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->logger->info('Heating equipment stopped successfully');
    }
    
    /**
     * Calculate heating cycle duration in minutes
     */
    private function calculateCycleDuration(HeatingCycle $cycle): int
    {
        $startTime = $cycle->getStartedAt();
        $endTime = $cycle->getCompletedAt() ?? new DateTime();
        
        return (int) ceil(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
    }
}