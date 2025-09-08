<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Heating;

use HotTubController\Application\Actions\Heating\HeatingStatusAction;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Storage\QueryBuilder;
use HotTubController\Services\WirelessTagClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use DateTime;

class HeatingStatusActionTest extends TestCase
{
    private HeatingStatusAction $action;
    private LoggerInterface $logger;
    private WirelessTagClient $wirelessTagClient;
    private HeatingEventRepository $eventRepository;
    private HeatingCycleRepository $cycleRepository;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->wirelessTagClient = $this->createMock(WirelessTagClient::class);
        $this->eventRepository = $this->createMock(HeatingEventRepository::class);
        $this->cycleRepository = $this->createMock(HeatingCycleRepository::class);

        $this->action = new HeatingStatusAction(
            $this->logger,
            $this->wirelessTagClient,
            $this->eventRepository,
            $this->cycleRepository
        );
    }

    public function testFullSystemStatus(): void
    {
        $request = $this->createRequest('GET', '/api/heating-status');

        // Mock temperature reading
        $this->wirelessTagClient->expects($this->once())
            ->method('getCachedTemperatureData')
            ->willReturn([
                [
                    'temperature' => 89.5,
                    'name' => 'Hot Tub Sensor',
                    'timestamp' => (new DateTime())->format('c'),
                    'battery' => 85,
                    'signal' => -45,
                ]
            ]);
            
        $this->wirelessTagClient->expects($this->once())
            ->method('processTemperatureData')
            ->willReturn([
                'water_temperature' => ['fahrenheit' => 89.5],
                'sensor_info' => [
                    'name' => 'Hot Tub Sensor',
                    'timestamp' => (new DateTime())->format('c'),
                    'battery_level' => 85,
                    'signal_strength' => -45
                ]
            ]);

        // Mock active cycle
        $mockCycle = $this->createMock(HeatingCycle::class);
        $mockCycle->method('getId')->willReturn('cycle-123');
        $mockCycle->method('getStatus')->willReturn(HeatingCycle::STATUS_HEATING);
        $mockCycle->method('getStartedAt')->willReturn(new DateTime('-30 minutes'));
        $mockCycle->method('getTargetTemp')->willReturn(102.0);
        $mockCycle->method('getCurrentTemp')->willReturn(89.5);
        $mockCycle->method('getEstimatedCompletion')->willReturn(new DateTime('+20 minutes'));
        $mockCycle->method('getLastCheck')->willReturn(new DateTime('-5 minutes'));
        $mockCycle->method('getElapsedTime')->willReturn(1800); // 30 minutes
        $mockCycle->method('getEstimatedTimeRemaining')->willReturn(1200); // 20 minutes
        $mockCycle->method('getTemperatureDifference')->willReturn(12.5);
        $mockCycle->method('getMetadata')->willReturn(['triggered_by_event' => 'event-456']);

        $cycleQuery = $this->createMock(QueryBuilder::class);
        $cycleQuery->method('where')->willReturnSelf();
        $cycleQuery->method('orderBy')->willReturnSelf();
        $cycleQuery->method('limit')->willReturnSelf();
        $cycleQuery->method('get')->willReturn([$mockCycle]);

        $this->cycleRepository->expects($this->atLeastOnce())
            ->method('query')
            ->willReturn($cycleQuery);

        // Mock next scheduled event
        $mockEvent = $this->createMock(HeatingEvent::class);
        $mockEvent->method('getId')->willReturn('event-789');
        $mockEvent->method('getEventType')->willReturn(HeatingEvent::EVENT_TYPE_START);
        $mockEvent->method('getScheduledFor')->willReturn(new DateTime('+2 hours'));
        $mockEvent->method('getTargetTemp')->willReturn(104.0);
        $mockEvent->method('getTimeUntilExecution')->willReturn(7200); // 2 hours
        $mockEvent->method('getCronExpression')->willReturn('0 20 * * *');
        $mockEvent->method('getMetadata')->willReturn([
            'name' => 'Evening Soak',
            'description' => 'Relaxing evening session'
        ]);

        $this->eventRepository->expects($this->once())
            ->method('getNextScheduledEvent')
            ->with(HeatingEvent::EVENT_TYPE_START)
            ->willReturn($mockEvent);

        // Mock health checks
        $this->eventRepository->expects($this->once())
            ->method('findPastDueEvents')
            ->willReturn([]);

        $this->eventRepository->expects($this->once())
            ->method('countScheduledEvents')
            ->willReturn(3);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(200, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);

        // Verify response structure
        $this->assertArrayHasKey('timestamp', $responseBody);
        $this->assertArrayHasKey('temperature', $responseBody);
        $this->assertArrayHasKey('active_cycle', $responseBody);
        $this->assertArrayHasKey('next_scheduled_event', $responseBody);
        $this->assertArrayHasKey('system_health', $responseBody);

        // Verify temperature data
        $this->assertEquals(89.5, $responseBody['temperature']['value']);
        $this->assertEquals('fahrenheit', $responseBody['temperature']['unit']);
        $this->assertEquals('Hot Tub Sensor', $responseBody['temperature']['sensor_name']);
        $this->assertEquals(85, $responseBody['temperature']['battery_level']);

        // Verify active cycle data
        $this->assertEquals('cycle-123', $responseBody['active_cycle']['id']);
        $this->assertEquals(HeatingCycle::STATUS_HEATING, $responseBody['active_cycle']['status']);
        $this->assertEquals(102.0, $responseBody['active_cycle']['target_temp']);
        $this->assertEquals(89.5, $responseBody['active_cycle']['current_temp']);
        $this->assertEquals(1800, $responseBody['active_cycle']['elapsed_time_seconds']);

        // Verify next scheduled event
        $this->assertEquals('event-789', $responseBody['next_scheduled_event']['id']);
        $this->assertEquals(104.0, $responseBody['next_scheduled_event']['target_temp']);
        $this->assertEquals('Evening Soak', $responseBody['next_scheduled_event']['name']);
        $this->assertEquals(7200, $responseBody['next_scheduled_event']['time_until_execution']);

        // Verify system health
        $this->assertEquals('healthy', $responseBody['system_health']['status']);
        $this->assertEquals(3, $responseBody['system_health']['statistics']['scheduled_events']);
        $this->assertEquals(1, $responseBody['system_health']['statistics']['active_cycles']);
    }

    public function testStatusWithNoActiveCycleOrScheduledEvents(): void
    {
        $request = $this->createRequest('GET', '/api/heating-status');

        // Mock temperature reading
        $this->wirelessTagClient->method('getCachedTemperatureData')
            ->willReturn([['temperature' => 75.0, 'name' => 'Sensor']]);
            
        $this->wirelessTagClient->method('processTemperatureData')
            ->willReturn([
                'water_temperature' => ['fahrenheit' => 75.0],
                'sensor_info' => ['name' => 'Sensor']
            ]);

        // Mock no active cycles
        $cycleQuery = $this->createMock(QueryBuilder::class);
        $cycleQuery->method('where')->willReturnSelf();
        $cycleQuery->method('orderBy')->willReturnSelf();
        $cycleQuery->method('limit')->willReturnSelf();
        $cycleQuery->method('get')->willReturn([]);

        $this->cycleRepository->expects($this->atLeastOnce())
            ->method('query')
            ->willReturn($cycleQuery);

        // Mock no scheduled events
        $this->eventRepository->expects($this->once())
            ->method('getNextScheduledEvent')
            ->willReturn(null);

        // Mock health checks
        $this->eventRepository->method('findPastDueEvents')->willReturn([]);
        $this->eventRepository->method('countScheduledEvents')->willReturn(0);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $responseBody = json_decode((string) $response->getBody(), true);

        $this->assertNull($responseBody['active_cycle']);
        $this->assertNull($responseBody['next_scheduled_event']);
        $this->assertEquals(0, $responseBody['system_health']['statistics']['scheduled_events']);
        $this->assertEquals(0, $responseBody['system_health']['statistics']['active_cycles']);
    }

    public function testStatusWithTemperatureSensorFailure(): void
    {
        $request = $this->createRequest('GET', '/api/heating-status');

        // Mock sensor failure
        $this->wirelessTagClient->method('getCachedTemperatureData')
            ->willReturn([]);

        // Mock no cycles or events
        $cycleQuery = $this->createMock(QueryBuilder::class);
        $cycleQuery->method('where')->willReturnSelf();
        $cycleQuery->method('orderBy')->willReturnSelf();
        $cycleQuery->method('limit')->willReturnSelf();
        $cycleQuery->method('get')->willReturn([]);

        $this->cycleRepository->method('query')->willReturn($cycleQuery);
        $this->eventRepository->method('getNextScheduledEvent')->willReturn(null);
        $this->eventRepository->method('findPastDueEvents')->willReturn([]);
        $this->eventRepository->method('countScheduledEvents')->willReturn(0);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $responseBody = json_decode((string) $response->getBody(), true);

        $this->assertNull($responseBody['temperature']);
    }

    public function testSystemHealthWithIssues(): void
    {
        $request = $this->createRequest('GET', '/api/heating-status');

        // Mock basic data
        $this->wirelessTagClient->method('getCachedTemperatureData')
            ->willReturn([['temperature' => 85.0, 'name' => 'Sensor']]);
            
        $this->wirelessTagClient->method('processTemperatureData')
            ->willReturn([
                'water_temperature' => ['fahrenheit' => 85.0],
                'sensor_info' => ['name' => 'Sensor']
            ]);

        // Mock long-running cycle (over 4 hours)
        $mockCycle = $this->createMock(HeatingCycle::class);
        $mockCycle->method('getId')->willReturn('stuck-cycle');
        $mockCycle->method('getElapsedTime')->willReturn(15000); // 4+ hours

        $cycleQuery = $this->createMock(QueryBuilder::class);
        $cycleQuery->method('where')->willReturnSelf();
        $cycleQuery->method('orderBy')->willReturnSelf();
        $cycleQuery->method('limit')->willReturnSelf();
        $cycleQuery->method('get')->willReturn([$mockCycle]);

        $this->cycleRepository->method('query')->willReturn($cycleQuery);

        // Mock past due events
        $pastDueEvent = $this->createMock(HeatingEvent::class);
        $this->eventRepository->method('findPastDueEvents')->willReturn([$pastDueEvent]);
        $this->eventRepository->method('getNextScheduledEvent')->willReturn(null);
        $this->eventRepository->method('countScheduledEvents')->willReturn(1);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $responseBody = json_decode((string) $response->getBody(), true);

        $this->assertEquals('warning', $responseBody['system_health']['status']);
        $this->assertCount(2, $responseBody['system_health']['issues']);

        // Check for past due events issue
        $issues = array_column($responseBody['system_health']['issues'], 'type');
        $this->assertContains('past_due_events', $issues);
        $this->assertContains('long_running_cycle', $issues);
    }

    public function testActionHandlesExceptionsGracefully(): void
    {
        $request = $this->createRequest('GET', '/api/heating-status');

        // Mock exception during temperature reading
        $this->wirelessTagClient->method('getCachedTemperatureData')
            ->willThrowException(new \Exception('Sensor communication failed'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to get current temperature', $this->isType('array'));


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(200, $response->getStatusCode()); // Still returns 200 with partial data
        $responseBody = json_decode((string) $response->getBody(), true);

        $this->assertNull($responseBody['temperature']);
        $this->assertNull($responseBody['active_cycle']);
        $this->assertNull($responseBody['next_scheduled_event']);
        $this->assertEquals('healthy', $responseBody['system_health']['status']);
    }

    private function createRequest(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }

}