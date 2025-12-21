<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\BlindsController;
use HotTub\Contracts\IftttClientInterface;

/**
 * Unit tests for BlindsController.
 *
 * Tests dining room blinds control via IFTTT webhooks.
 * Feature is isolated - only works when BLINDS_FEATURE_ENABLED is set.
 */
class BlindsControllerTest extends TestCase
{
    private string $logFile;
    private IftttClientInterface $mockIftttClient;
    private array $config;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/test-events-' . uniqid() . '.log';
        $this->mockIftttClient = $this->createMock(IftttClientInterface::class);
        $this->config = ['BLINDS_FEATURE_ENABLED' => 'true'];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ========== Feature Flag Tests ==========

    public function testOpenReturns404WhenFeatureDisabled(): void
    {
        $config = ['BLINDS_FEATURE_ENABLED' => 'false'];
        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $config);

        $response = $controller->open();

        $this->assertEquals(404, $response['status']);
        $this->assertEquals('Blinds feature not enabled', $response['body']['error']);
    }

    public function testCloseReturns404WhenFeatureDisabled(): void
    {
        $config = ['BLINDS_FEATURE_ENABLED' => 'false'];
        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $config);

        $response = $controller->close();

        $this->assertEquals(404, $response['status']);
        $this->assertEquals('Blinds feature not enabled', $response['body']['error']);
    }

    public function testOpenReturns404WhenFeatureNotSet(): void
    {
        $config = [];
        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $config);

        $response = $controller->open();

        $this->assertEquals(404, $response['status']);
    }

    // ========== Open Blinds Tests ==========

    public function testOpenTriggersCorrectIftttEvent(): void
    {
        $this->mockIftttClient->expects($this->once())
            ->method('trigger')
            ->with('open-dining-room-blinds')
            ->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $controller->open();
    }

    public function testOpenReturnsSuccessOnIftttSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $response = $controller->open();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('blinds_open', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);
    }

    public function testOpenReturns500OnIftttFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $response = $controller->open();

        $this->assertEquals(500, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('blinds_open', $response['body']['action']);
    }

    // ========== Close Blinds Tests ==========

    public function testCloseTriggersCorrectIftttEvent(): void
    {
        $this->mockIftttClient->expects($this->once())
            ->method('trigger')
            ->with('close-dining-room-blinds')
            ->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $controller->close();
    }

    public function testCloseReturnsSuccessOnIftttSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $response = $controller->close();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('blinds_close', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);
    }

    public function testCloseReturns500OnIftttFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $response = $controller->close();

        $this->assertEquals(500, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('blinds_close', $response['body']['action']);
    }

    // ========== isEnabled Tests ==========

    public function testIsEnabledReturnsTrueWhenFeatureEnabled(): void
    {
        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $this->config);

        $this->assertTrue($controller->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenFeatureDisabled(): void
    {
        $config = ['BLINDS_FEATURE_ENABLED' => 'false'];
        $controller = new BlindsController($this->logFile, $this->mockIftttClient, $config);

        $this->assertFalse($controller->isEnabled());
    }
}
