<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\CronAuthenticatedAction;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
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
 * StartHeatingAction - Triggered by cron to begin heating cycles
 * 
 * This endpoint is called by self-deleting cron jobs to start heating cycles.
 * It authenticates using the cron API key, starts the heating equipment,
 * creates the heating cycle record, and schedules the first temperature
 * monitoring check.
 */
class StartHeatingAction extends CronAuthenticatedAction
{
    private WirelessTagClient $wirelessTagClient;
    private IftttWebhookClient $iftttClient;
    private CronManager $cronManager;
    private CronSecurityManager $securityManager;
    private CronJobBuilder $cronJobBuilder;
    private HeatingCycleRepository $cycleRepository;
    private HeatingEventRepository $eventRepository;
    
    public function __construct(
        LoggerInterface $logger,
        CronSecurityManager $securityManager,
        WirelessTagClient $wirelessTagClient,
        IftttWebhookClient $iftttClient,
        CronManager $cronManager,
        CronJobBuilder $cronJobBuilder,
        HeatingCycleRepository $cycleRepository,
        HeatingEventRepository $eventRepository
    ) {
        parent::__construct($logger, $securityManager);
        $this->wirelessTagClient = $wirelessTagClient;
        $this->iftttClient = $iftttClient;
        $this->cronManager = $cronManager;
        $this->securityManager = $securityManager;
        $this->cronJobBuilder = $cronJobBuilder;
        $this->cycleRepository = $cycleRepository;
        $this->eventRepository = $eventRepository;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Parse request parameters
        $input = $this->getJsonInput($request);
        $eventId = $input['id'] ?? null;
        $targetTemp = (float) ($input['target_temp'] ?? 104.0);
        $scheduledTime = $input['scheduled_time'] ?? null;
        
        $this->logger->info('Starting heating cycle', [
            'event_id' => $eventId,
            'target_temp' => $targetTemp,
            'scheduled_time' => $scheduledTime,
        ]);
        
        try {
            // Authentication handled by parent CronAuthenticatedAction
            
            // Validate parameters
            $this->validateStartHeatingParameters($eventId, $targetTemp);
            
            // Update the triggering event status
            $triggeringEvent = $this->updateTriggeringEvent($eventId);
            
            // Get current temperature
            $currentTemp = $this->getCurrentTemperature();
            
            // Validate temperature differential
            if ($currentTemp >= $targetTemp) {
                return $this->jsonResponse([
                    'status' => 'already_at_target',
                    'current_temp' => $currentTemp,
                    'target_temp' => $targetTemp,
                    'message' => 'Water is already at or above target temperature',
                ]);
            }
            
            // Start heating equipment
            $this->startHeatingEquipment();
            
            // Create heating cycle record
            $cycle = $this->createHeatingCycle($eventId, $currentTemp, $targetTemp);
            
            // Calculate first monitor check time
            $heatingTimeEstimate = $this->cronJobBuilder->calculateHeatingTime($currentTemp, $targetTemp);
            $firstCheckTime = (new DateTime())->modify("+{$heatingTimeEstimate} minutes");
            
            // Schedule first monitoring check
            $this->scheduleMonitoringCheck($cycle->getId(), $firstCheckTime);
            
            $this->logger->info('Heating cycle started successfully', [
                'cycle_id' => $cycle->getId(),
                'current_temp' => $currentTemp,
                'target_temp' => $targetTemp,
                'estimated_completion' => $firstCheckTime->format('Y-m-d H:i:s'),
            ]);
            
            return $this->jsonResponse([
                'status' => 'heating_started',
                'cycle_id' => $cycle->getId(),
                'current_temp' => $currentTemp,
                'target_temp' => $targetTemp,
                'estimated_completion' => $firstCheckTime->format('c'),
                'next_check' => $firstCheckTime->format('c'),
                'heating_time_estimate_minutes' => $heatingTimeEstimate,
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to start heating cycle', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Try to clean up any partial state
            $this->emergencyCleanup($eventId);
            
            return $this->errorResponse('Failed to start heating: ' . $e->getMessage(), 500);
        }
    }
    
    
    /**
     * Validate start heating parameters
     */
    private function validateStartHeatingParameters(string $eventId, float $targetTemp): void
    {
        if (empty($eventId)) {
            throw new RuntimeException('Missing event ID parameter');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $eventId)) {
            throw new RuntimeException('Invalid event ID format');
        }
        
        if ($targetTemp < 50.0 || $targetTemp > 110.0) {
            throw new RuntimeException("Target temperature out of safe range (50-110°F): {$targetTemp}");
        }
    }
    
    /**
     * Update the triggering event status to triggered
     */
    private function updateTriggeringEvent(string $eventId): ?HeatingEvent
    {
        try {
            $event = $this->eventRepository->findById($eventId);
            if ($event && $event->isScheduled()) {
                $event->trigger();
                $this->eventRepository->save($event);
                return $event;
            }
        } catch (Exception $e) {
            $this->logger->warning('Could not update triggering event', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }
    
    /**
     * Get current water temperature from WirelessTag
     */
    private function getCurrentTemperature(): float
    {
        $this->logger->info('Getting current temperature from WirelessTag');
        
        // Request fresh temperature reading
        $requestResult = $this->wirelessTagClient->requestImmediatePostback();
        if (!$requestResult['success']) {
            $this->logger->warning('Failed to request immediate temperature reading', $requestResult);
        }
        
        // Wait briefly for fresh reading
        sleep(2);
        
        // Get temperature data
        $temperatureData = $this->wirelessTagClient->getTemperatureData();
        
        if (empty($temperatureData)) {
            throw new RuntimeException('No temperature data available from sensors');
        }
        
        // Use the first sensor's temperature (primary hot tub sensor)
        $primarySensor = reset($temperatureData);
        $currentTemp = $primarySensor['temperature'];
        
        $this->logger->info('Current temperature retrieved', [
            'temperature' => $currentTemp,
            'sensor_name' => $primarySensor['name'] ?? 'Unknown',
        ]);
        
        return $currentTemp;
    }
    
    /**
     * Start heating equipment via IFTTT
     */
    private function startHeatingEquipment(): void
    {
        $this->logger->info('Starting heating equipment');
        
        $result = $this->iftttClient->triggerEvent('hot-tub-heat-on');
        
        if (!$result['success']) {
            throw new RuntimeException('Failed to start heating equipment: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->logger->info('Heating equipment started successfully');
    }
    
    /**
     * Create heating cycle record
     */
    private function createHeatingCycle(string $eventId, float $currentTemp, float $targetTemp): HeatingCycle
    {
        $cycle = new HeatingCycle();
        $cycle->setCurrentTemp($currentTemp);
        $cycle->setTargetTemp($targetTemp);
        $cycle->setStatus(HeatingCycle::STATUS_HEATING);
        $cycle->setStartedAt(new DateTime());
        $cycle->addMetadata('triggered_by_event', $eventId);
        $cycle->addMetadata('started_via_cron', true);
        
        $this->cycleRepository->save($cycle);
        
        $this->logger->info('Created heating cycle record', [
            'cycle_id' => $cycle->getId(),
            'event_id' => $eventId,
        ]);
        
        return $cycle;
    }
    
    /**
     * Schedule first temperature monitoring check
     */
    private function scheduleMonitoringCheck(string $cycleId, DateTime $checkTime): void
    {
        $monitorId = 'monitor-' . $cycleId . '-' . time();
        
        // Build curl config for monitor endpoint
        $cronConfig = $this->cronJobBuilder->buildMonitorTempCron($checkTime, $cycleId, $monitorId);
        
        // Add the monitoring cron
        $cronId = $this->cronManager->addMonitoringEvent(
            $checkTime,
            $monitorId,
            $cronConfig['config_file']
        );
        
        $this->logger->info('Scheduled first monitoring check', [
            'cycle_id' => $cycleId,
            'monitor_id' => $monitorId,
            'check_time' => $checkTime->format('Y-m-d H:i:s'),
            'cron_id' => $cronId,
        ]);
    }
    
    /**
     * Emergency cleanup in case of failures
     */
    private function emergencyCleanup(string $eventId): void
    {
        try {
            $this->logger->info('Performing emergency cleanup', ['event_id' => $eventId]);
            
            // Try to turn off heating equipment
            $this->iftttClient->triggerEvent('hot-tub-heat-off');
            
            // Clean up any monitoring crons
            $this->cronManager->removeMonitoringEvents();
            
        } catch (Exception $e) {
            $this->logger->error('Emergency cleanup failed', [
                'event_id' => $eventId,
                'cleanup_error' => $e->getMessage(),
            ]);
        }
    }
}