<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Heating;

use HotTubController\Application\Actions\Heating\ScheduleHeatingAction;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Services\CronManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use DateTime;
use ReflectionClass;

class ScheduleHeatingActionTest extends TestCase
{
    private ScheduleHeatingAction $action;
    private LoggerInterface $logger;
    private TokenService $tokenService;
    private HeatingEventRepository $eventRepository;
    private CronManager $cronManager;
    private CronJobBuilder $cronJobBuilder;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->eventRepository = $this->createMock(HeatingEventRepository::class);
        $this->cronManager = $this->createMock(CronManager::class);
        $this->cronJobBuilder = $this->createMock(CronJobBuilder::class);

        $this->action = new ScheduleHeatingAction(
            $this->logger,
            $this->tokenService,
            $this->eventRepository,
            $this->cronManager,
            $this->cronJobBuilder
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

        // Authentication is bypassed in unit test - testing core business logic

        // Mock no overlapping events
        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([]);

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
            ->with($this->anything(), $this->anything(), '/tmp/cron-config')
            ->willReturn('cron-123');


        // Execute the action
        $response = $this->invokeProtectedAction($request);

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
        // This test is no longer relevant for unit testing since authentication is bypassed
        // In unit tests, we test core business logic, not authentication
        // Authentication is tested separately in:
        // - TokenValidationMiddlewareTest (unit tests for auth logic)
        // - HeatingManagementWorkflowTest (integration tests with full auth flow)
        $this->markTestSkipped('Authentication testing is handled in middleware and integration tests');
    }

    public function testSchedulingFailsWithInvalidToken(): void
    {
        // This test is no longer relevant for unit testing since authentication is bypassed
        // In unit tests, we test core business logic, not authentication
        // Authentication is tested separately in:
        // - TokenValidationMiddlewareTest (unit tests for auth logic)
        // - HeatingManagementWorkflowTest (integration tests with full auth flow)
        $this->markTestSkipped('Authentication testing is handled in middleware and integration tests');
    }

    public function testSchedulingFailsWithPastTime(): void
    {
        $pastTime = new DateTime('-1 hour');
        
        $request = $this->createRequest('POST', '/api/schedule-heating', [
            'start_time' => $pastTime->format('c'),
            'target_temp' => 102.0
        ]);
        $request = $request->withHeader('Authorization', 'Bearer valid-token');

        // Authentication is bypassed in unit test


        $response = $this->invokeProtectedAction($request);

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

        // Authentication is bypassed in unit test


        $response = $this->invokeProtectedAction($request);

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

        // Authentication is bypassed in unit test

        // Mock overlapping event
        $existingEvent = $this->createMock(HeatingEvent::class);
        $existingEvent->method('getId')->willReturn('existing-event');
        $existingEvent->method('getScheduledFor')->willReturn($startTime);

        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([$existingEvent]);


        $response = $this->invokeProtectedAction($request);

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

        // Authentication is bypassed in unit test

        $this->eventRepository->expects($this->once())
            ->method('findByTimeRange')
            ->willReturn([]);
            
        $this->eventRepository->expects($this->exactly(2))
            ->method('save')
            ->willReturn(true);

        // Mock the other required methods
        $this->cronJobBuilder->method('buildStartHeatingCron')->willReturn(['config_file' => '/tmp/config', 'cron_id' => 'cron-123']);
        $this->cronManager->method('addStartEvent')
            ->with($this->anything(), $this->anything(), '/tmp/config')
            ->willReturn('cron-123');

        $response = $this->invokeProtectedAction($request);

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
    
    private function createResponse(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }
    
    /**
     * Helper method to invoke the protected action method directly for unit testing
     */
    private function invokeProtectedAction(ServerRequestInterface $request): ResponseInterface
    {
        $reflection = new ReflectionClass($this->action);
        $method = $reflection->getMethod('action');
        $method->setAccessible(true);
        
        return $method->invoke($this->action, $request, $this->createResponse(), []);
    }
}