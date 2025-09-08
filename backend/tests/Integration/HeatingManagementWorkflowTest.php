<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration;

use HotTubController\Application\Actions\Heating\ScheduleHeatingAction;
use HotTubController\Application\Actions\Heating\CancelScheduledHeatingAction;
use HotTubController\Application\Actions\Heating\ListHeatingEventsAction;
use HotTubController\Application\Actions\Heating\HeatingStatusAction;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use HotTubController\Services\CronManager;
use HotTubController\Services\WirelessTagClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use DateTime;

/**
 * Integration test for the complete heating management workflow
 * Tests the end-to-end functionality of scheduling, listing, and canceling heating events
 */
class HeatingManagementWorkflowTest extends TestCase
{
    private string $testStorageDir;
    private JsonStorageManager $storageManager;
    private HeatingEventRepository $eventRepository;
    private HeatingCycleRepository $cycleRepository;
    private TokenService $tokenService;
    private ScheduleHeatingAction $scheduleAction;
    private CancelScheduledHeatingAction $cancelAction;
    private ListHeatingEventsAction $listAction;
    private HeatingStatusAction $statusAction;

    protected function setUp(): void
    {
        // Create temporary storage directory
        $this->testStorageDir = sys_get_temp_dir() . '/hot-tub-test-' . uniqid();
        mkdir($this->testStorageDir, 0755, true);

        // Initialize storage and repositories
        $this->storageManager = new JsonStorageManager($this->testStorageDir);
        $this->eventRepository = new HeatingEventRepository($this->storageManager);
        $this->cycleRepository = new HeatingCycleRepository($this->storageManager);

        // Mock external dependencies
        $this->tokenService = $this->createMock(TokenService::class);
        $this->tokenService->method('validateToken')->willReturn(true);

        $wirelessTagClient = $this->createMock(WirelessTagClient::class);
        $wirelessTagClient->method('getFreshTemperatureData')->willReturn([
            ['temperature' => 88.5, 'name' => 'Hot Tub Sensor', 'timestamp' => (new DateTime())->format('c')]
        ]);
        $wirelessTagClient->method('processTemperatureData')->willReturn([
            'water_temperature' => ['fahrenheit' => 88.5],
            'sensor_info' => ['name' => 'Hot Tub Sensor']
        ]);

        $cronManager = $this->createMock(CronManager::class);
        $cronManager->method('addStartEvent')->willReturn('cron-' . uniqid());
        $cronManager->method('removeStartEvents')->willReturn(1);

        $cronJobBuilder = $this->createMock(CronJobBuilder::class);
        $cronJobBuilder->method('buildStartHeatingCron')->willReturn(['config_file' => '/tmp/test-config', 'cron_id' => 'test-cron']);

        $logger = new NullLogger();

        // Initialize actions
        $this->scheduleAction = new ScheduleHeatingAction(
            $logger,
            $this->tokenService,
            $this->eventRepository,
            $cronManager,
            $cronJobBuilder,
            $wirelessTagClient
        );

        $this->cancelAction = new CancelScheduledHeatingAction(
            $logger,
            $this->tokenService,
            $this->eventRepository,
            $cronManager
        );

        $this->listAction = new ListHeatingEventsAction(
            $logger,
            $this->tokenService,
            $this->eventRepository,
            $this->cycleRepository
        );

        $this->statusAction = new HeatingStatusAction(
            $logger,
            $wirelessTagClient,
            $this->eventRepository,
            $this->cycleRepository
        );
    }

    protected function tearDown(): void
    {
        // Clean up test storage directory
        if (is_dir($this->testStorageDir)) {
            $this->removeDirectory($this->testStorageDir);
        }
    }

    public function testCompleteHeatingManagementWorkflow(): void
    {
        // Step 1: Schedule a heating event
        $startTime = new DateTime('+2 hours');
        $scheduleRequest = $this->createAuthenticatedRequest('POST', '/api/schedule-heating', [
            'start_time' => $startTime->format('c'),
            'target_temp' => 102.5,
            'name' => 'Morning Warmup',
            'description' => 'Pre-breakfast hot tub session'
        ]);

        $scheduleResponse = $this->scheduleAction->__invoke(
            $scheduleRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $scheduleData = json_decode((string) $scheduleResponse->getBody(), true);

        $this->assertEquals(200, $scheduleResponse->getStatusCode());
        $this->assertEquals('scheduled', $scheduleData['status']);
        $this->assertEquals(102.5, $scheduleData['target_temp']);
        $this->assertEquals('Morning Warmup', $scheduleData['name']);
        $this->assertArrayHasKey('event_id', $scheduleData);

        $eventId = $scheduleData['event_id'];

        // Step 2: Schedule another heating event
        $secondStartTime = new DateTime('+4 hours');
        $secondScheduleRequest = $this->createAuthenticatedRequest('POST', '/api/schedule-heating', [
            'start_time' => $secondStartTime->format('c'),
            'target_temp' => 104.0,
            'name' => 'Evening Soak'
        ]);

        $secondScheduleResponse = $this->scheduleAction->__invoke(
            $secondScheduleRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $secondScheduleData = json_decode((string) $secondScheduleResponse->getBody(), true);
        $secondEventId = $secondScheduleData['event_id'];

        $this->assertEquals('scheduled', $secondScheduleData['status']);

        // Step 3: List heating events
        $listRequest = $this->createAuthenticatedRequest('GET', '/api/list-heating-events');

        $listResponse = $this->listAction->__invoke(
            $listRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $listData = json_decode((string) $listResponse->getBody(), true);

        $this->assertEquals(200, $listResponse->getStatusCode());
        $this->assertArrayHasKey('events', $listData);
        $this->assertArrayHasKey('pagination', $listData);
        $this->assertEquals(2, $listData['pagination']['total']);
        $this->assertCount(2, $listData['events']);

        // Verify events are ordered by scheduled_for DESC (most recent first)
        $firstEvent = $listData['events'][0];
        $secondEvent = $listData['events'][1];

        $this->assertEquals($secondEventId, $firstEvent['id']);
        $this->assertEquals($eventId, $secondEvent['id']);
        $this->assertEquals('Evening Soak', $firstEvent['name']);
        $this->assertEquals('Morning Warmup', $secondEvent['name']);

        // Step 4: Get heating status
        $statusRequest = $this->createRequest('GET', '/api/heating-status');

        $statusResponse = $this->statusAction->__invoke(
            $statusRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $statusData = json_decode((string) $statusResponse->getBody(), true);

        $this->assertEquals(200, $statusResponse->getStatusCode());
        $this->assertArrayHasKey('temperature', $statusData);
        $this->assertArrayHasKey('next_scheduled_event', $statusData);
        $this->assertArrayHasKey('system_health', $statusData);

        // Verify next scheduled event is the first one chronologically
        $this->assertEquals($eventId, $statusData['next_scheduled_event']['id']);
        $this->assertEquals('Morning Warmup', $statusData['next_scheduled_event']['name']);

        // Step 5: Cancel the first heating event
        $cancelRequest = $this->createAuthenticatedRequest('POST', '/api/cancel-scheduled-heating', [
            'event_id' => $eventId
        ]);

        $cancelResponse = $this->cancelAction->__invoke(
            $cancelRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $cancelData = json_decode((string) $cancelResponse->getBody(), true);

        $this->assertEquals(200, $cancelResponse->getStatusCode());
        $this->assertEquals('cancelled', $cancelData['status']);
        $this->assertEquals($eventId, $cancelData['event_id']);
        $this->assertEquals('Morning Warmup', $cancelData['name']);

        // Step 6: Verify the event is cancelled in the list
        $finalListResponse = $this->listAction->__invoke(
            $listRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $finalListData = json_decode((string) $finalListResponse->getBody(), true);

        // Find the cancelled event
        $cancelledEvent = null;
        foreach ($finalListData['events'] as $event) {
            if ($event['id'] === $eventId) {
                $cancelledEvent = $event;
                break;
            }
        }

        $this->assertNotNull($cancelledEvent);
        $this->assertEquals(HeatingEvent::STATUS_CANCELLED, $cancelledEvent['status']);

        // Step 7: Verify next scheduled event has changed in status
        $finalStatusResponse = $this->statusAction->__invoke(
            $statusRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $finalStatusData = json_decode((string) $finalStatusResponse->getBody(), true);

        // Next event should now be the second one
        $this->assertEquals($secondEventId, $finalStatusData['next_scheduled_event']['id']);
        $this->assertEquals('Evening Soak', $finalStatusData['next_scheduled_event']['name']);
    }

    public function testSchedulingWithOverlapPrevention(): void
    {
        // Schedule first event
        $startTime = new DateTime('+2 hours');
        $firstRequest = $this->createAuthenticatedRequest('POST', '/api/schedule-heating', [
            'start_time' => $startTime->format('c'),
            'target_temp' => 102.0
        ]);

        $firstResponse = $this->scheduleAction->__invoke(
            $firstRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $this->assertEquals(200, $firstResponse->getStatusCode());

        // Try to schedule overlapping event (within 30 minutes)
        $overlappingTime = (clone $startTime)->modify('+15 minutes');
        $overlappingRequest = $this->createAuthenticatedRequest('POST', '/api/schedule-heating', [
            'start_time' => $overlappingTime->format('c'),
            'target_temp' => 104.0
        ]);

        $overlappingResponse = $this->scheduleAction->__invoke(
            $overlappingRequest,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $overlappingData = json_decode((string) $overlappingResponse->getBody(), true);

        $this->assertEquals(400, $overlappingResponse->getStatusCode());
        $this->assertStringContainsString('Overlapping heating event detected', $overlappingData['message']);
    }

    public function testListEventsWithPagination(): void
    {
        // Schedule multiple events
        for ($i = 1; $i <= 5; $i++) {
            $startTime = new DateTime("+{$i} hours");
            $request = $this->createAuthenticatedRequest('POST', '/api/schedule-heating', [
                'start_time' => $startTime->format('c'),
                'target_temp' => 102.0 + $i,
                'name' => "Event {$i}"
            ]);

            $this->scheduleAction->__invoke(
                $request,
                $this->createMock(\Psr\Http\Message\ResponseInterface::class),
                []
            );
        }

        // List first page (limit 3)
        $request = $this->createAuthenticatedRequest('GET', '/api/list-heating-events?limit=3&offset=0');

        $response = $this->listAction->__invoke(
            $request,
            $this->createMock(\Psr\Http\Message\ResponseInterface::class),
            []
        );

        $data = json_decode((string) $response->getBody(), true);

        $this->assertEquals(5, $data['pagination']['total']);
        $this->assertEquals(3, $data['pagination']['limit']);
        $this->assertEquals(0, $data['pagination']['offset']);
        $this->assertTrue($data['pagination']['has_more']);
        $this->assertCount(3, $data['events']);
    }

    private function createRequest(string $method, string $uri, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        if (!empty($data)) {
            $request = $request->withParsedBody($data);
        }

        // Set request property for actions using reflection
        return $request;
    }

    private function createAuthenticatedRequest(string $method, string $uri, array $data = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->createRequest($method, $uri, $data);
        return $request->withHeader('Authorization', 'Bearer test-token');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }

        rmdir($dir);
    }
}