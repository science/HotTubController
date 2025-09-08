<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Heating;

use HotTubController\Application\Actions\Heating\ScheduleHeatingAction;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Services\CronManager;
use HotTubController\Services\WirelessTagClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use DateTime;

class ScheduleHeatingActionTest extends TestCase
{
    private ScheduleHeatingAction $action;
    private LoggerInterface $logger;
    private TokenService $tokenService;
    private HeatingEventRepository $eventRepository;
    private CronManager $cronManager;
    private CronJobBuilder $cronJobBuilder;
    private WirelessTagClient $wirelessTagClient;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->eventRepository = $this->createMock(HeatingEventRepository::class);
        $this->cronManager = $this->createMock(CronManager::class);
        $this->cronJobBuilder = $this->createMock(CronJobBuilder::class);
        $this->wirelessTagClient = $this->createMock(WirelessTagClient::class);

        $this->action = new ScheduleHeatingAction(
            $this->logger,
            $this->tokenService,
            $this->eventRepository,
            $this->cronManager,
            $this->cronJobBuilder,
            $this->wirelessTagClient
        );
    }

    public function testSuccessfulHeatingSchedule(): void
    {
        $startTime = new DateTime('+2 hours');
        $targetTemp = 102.5;
        
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => $startTime->format('c'),
            'target_temp' => $targetTemp,
            'name' => 'Evening Soak',
            'description' => 'Pre-dinner hot tub session'
        ]);
        $request = $request->withHeader('Authorization', 'Bearer test-token');

        // Mock authentication
        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('test-token')
            ->willReturn(true);

        $this->tokenService->expects($this->once())
            ->method('updateTokenLastUsed')
            ->with('test-token');

        // Mock no overlapping events
        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([]);

        // Mock current temperature
        $this->wirelessTagClient->expects($this->once())
            ->method('getFreshTemperatureData')
            ->willReturn([
                [
                    'temperature' => 88.5,
                    'name' => 'Hot Tub Sensor',
                    'timestamp' => (new DateTime())->format('c'),
                ]
            ]);
        
        $this->wirelessTagClient->expects($this->once())
            ->method('processTemperatureData')
            ->willReturn([
                'water_temperature' => ['fahrenheit' => 88.5],
                'sensor_info' => ['name' => 'Hot Tub Sensor']
            ]);

        // Mock event creation - repository save is called twice (create + update with cron_id)
        $this->eventRepository->expects($this->exactly(2))
            ->method('save')
            ->willReturn(true);

        // Mock cron scheduling
        $this->cronJobBuilder->expects($this->once())
            ->method('buildStartHeatingCron')
            ->willReturn(['config_file' => '/tmp/cron-config', 'cron_id' => 'cron-123']);

        $this->cronManager->expects($this->once())
            ->method('addStartEvent')
            ->willReturn('cron-123');


        // Execute the action
        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        // Verify response
        $responseBody = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200) {
            $this->fail('Expected status 200 but got ' . $response->getStatusCode() . '. Response: ' . print_r($responseBody, true));
        }
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('scheduled', $responseBody['status']);
        $this->assertEquals($targetTemp, $responseBody['target_temp']);
        $this->assertEquals('Evening Soak', $responseBody['name']);
    }

    public function testSchedulingFailsWithMissingAuthHeader(): void
    {
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => (new DateTime('+2 hours'))->format('c'),
            'target_temp' => 102.0
        ]);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Missing Authorization header', $responseBody['error']);
    }

    public function testSchedulingFailsWithInvalidToken(): void
    {
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => (new DateTime('+2 hours'))->format('c'),
            'target_temp' => 102.0
        ]);
        $request = $request->withHeader('Authorization', 'Bearer invalid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('invalid-token')
            ->willReturn(false);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Invalid or expired token', $responseBody['error']);
    }

    public function testSchedulingFailsWithPastTime(): void
    {
        $pastTime = new DateTime('-1 hour');
        
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => $pastTime->format('c'),
            'target_temp' => 102.0
        ]);
        $request = $request->withHeader('Authorization', 'Bearer valid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('start_time must be in the future', $responseBody['error']);
    }

    public function testSchedulingFailsWithTemperatureOutOfRange(): void
    {
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => (new DateTime('+2 hours'))->format('c'),
            'target_temp' => 120.0 // Too hot
        ]);
        $request = $request->withHeader('Authorization', 'Bearer valid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Target temperature out of safe range', $responseBody['error']);
    }

    public function testSchedulingFailsWithOverlappingEvent(): void
    {
        $startTime = new DateTime('+2 hours');
        
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => $startTime->format('c'),
            'target_temp' => 102.0
        ]);
        $request = $request->withHeader('Authorization', 'Bearer valid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);

        // Mock overlapping event
        $existingEvent = $this->createMock(HeatingEvent::class);
        $existingEvent->method('getId')->willReturn('existing-event');
        $existingEvent->method('getScheduledFor')->willReturn($startTime);

        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([$existingEvent]);


        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $this->assertEquals(400, $response->getStatusCode());
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Overlapping heating event detected', $responseBody['error']);
    }

    public function testSchedulingWithDefaultValues(): void
    {
        $startTime = new DateTime('+2 hours');
        
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => $startTime->format('c')
            // No target_temp (should default to 102.0)
            // No name or description
        ]);
        $request = $request->withHeader('Authorization', 'Bearer valid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);

        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([]);
            
        $this->eventRepository->expects($this->exactly(2))
            ->method('save')
            ->willReturn(true);

        // Mock current temperature below target
        $this->wirelessTagClient->expects($this->once())
            ->method('getFreshTemperatureData')
            ->willReturn([['temperature' => 85.0, 'name' => 'Sensor']]);
            
        $this->wirelessTagClient->expects($this->once())
            ->method('processTemperatureData')
            ->willReturn([
                'water_temperature' => ['fahrenheit' => 85.0],
                'sensor_info' => ['name' => 'Sensor']
            ]);


        // Mock the other required methods
        $this->cronJobBuilder->method('buildStartHeatingCron')->willReturn(['config_file' => '/tmp/config', 'cron_id' => 'cron-123']);
        $this->cronManager->method('addStartEvent')->willReturn('cron-123');

        $response = $this->action->__invoke($request, $this->createMock(\Psr\Http\Message\ResponseInterface::class), []);

        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals(102.0, $responseBody['target_temp']); // Default value
        $this->assertEquals('Scheduled Heating', $responseBody['name']); // Default name
    }

    private function createRequest(string $method, string $uri, array $data = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        
        if (!empty($data)) {
            $request = $request->withParsedBody($data);
        }
        
        return $request;
    }
}