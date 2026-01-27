<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\TargetTemperatureService;
use HotTub\Tests\Fixtures\HeatingCycleFixture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the target temperature heating system.
 *
 * Uses HeatingCycleFixture to simulate a complete heating cycle from 82°F to 103.5°F.
 * This tests the re-entrant cron job behavior by repeatedly calling checkAndAdjust()
 * with temperature data from the fixture.
 */
class TargetTemperatureIntegrationTest extends TestCase
{
    private string $stateFile;
    private string $equipmentStatusFile;
    private string $esp32TempFile;
    private MockObject&IftttClientInterface $mockIfttt;
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private EquipmentStatusService $equipmentStatus;
    private Esp32TemperatureService $esp32Temp;
    private TargetTemperatureService $service;
    private HeatingCycleFixture $fixture;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/target-temp-int-test-' . uniqid() . '.json';
        $this->equipmentStatusFile = sys_get_temp_dir() . '/equip-status-int-test-' . uniqid() . '.json';
        $this->esp32TempFile = sys_get_temp_dir() . '/esp32-temp-int-test-' . uniqid() . '.json';

        $this->mockIfttt = $this->createMock(IftttClientInterface::class);
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
        $this->esp32Temp = new Esp32TemperatureService($this->esp32TempFile, $this->equipmentStatus);

        $this->service = new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );

        $this->fixture = HeatingCycleFixture::load();
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateFile, $this->equipmentStatusFile, $this->esp32TempFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function storeReading(array $reading): void
    {
        $this->esp32Temp->store($this->fixture->toApiRequest($reading));
    }

    /**
     * Simulate a complete heating cycle from 82°F to 103.5°F.
     *
     * This test simulates what would happen in production:
     * 1. User starts heating to 103.5°F
     * 2. Cron job calls checkAndAdjust every minute
     * 3. Each call gets the current temperature and adjusts heater
     * 4. When target is reached, heater turns off and state is cleared
     */
    public function testFullHeatingCycleFromFixture(): void
    {
        $targetTempF = 103.5;

        // Expect heater ON to be triggered once at the start
        $this->mockIfttt->expects($this->exactly(2))
            ->method('trigger')
            ->willReturnCallback(function (string $event) {
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) {
                    $this->assertEquals('hot-tub-heat-on', $event);
                } else {
                    $this->assertEquals('hot-tub-heat-off', $event);
                }
                return true;
            });

        // Expect cron jobs to be scheduled for each check (until target reached)
        // And one cleanup at the end
        $this->mockCrontab->expects($this->atLeastOnce())
            ->method('addEntry')
            ->with($this->stringContains('HOTTUB:heat-target'));

        // Called twice: once for cleanup, once for race condition protection
        $this->mockCrontab->expects($this->exactly(2))
            ->method('removeByPattern')
            ->with('HOTTUB:heat-target');

        // Start heating
        $this->service->start($targetTempF);

        // Store initial temperature
        $initialReading = $this->fixture->getReading(0);
        $this->storeReading($initialReading);

        // First check - should turn heater ON
        $result = $this->service->checkAndAdjust();
        $this->assertTrue($result['heater_turned_on']);
        $this->assertTrue($result['heating']);
        $this->assertTrue($this->equipmentStatus->getStatus()['heater']['on']);

        // Simulate intermediate temperature readings
        // Skip through to near the target
        foreach ([10, 20, 30, 40] as $minute) {
            $reading = $this->fixture->getReading($minute);
            if ($reading) {
                $this->storeReading($reading);
                $result = $this->service->checkAndAdjust();

                // Should still be heating (not at target yet)
                $this->assertTrue($result['heating'], "Should still be heating at minute $minute");
                $this->assertFalse($result['heater_turned_on'], "Should not trigger heater again at minute $minute");
            }
        }

        // Final reading - at target
        $targetReading = $this->fixture->getFirstReadingAtOrAbove($targetTempF);
        $this->storeReading($targetReading);

        // Check should turn heater OFF and clear state
        $result = $this->service->checkAndAdjust();
        $this->assertTrue($result['target_reached']);
        $this->assertTrue($result['heater_turned_off']);
        $this->assertFalse($result['active']);

        // Verify state is cleared
        $state = $this->service->getState();
        $this->assertFalse($state['active']);

        // Verify heater is off
        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);
    }

    /**
     * Test that heating stops correctly when target is reached mid-cycle.
     */
    public function testHeatingStopsAtIntermediateTarget(): void
    {
        $targetTempF = 95.0; // Somewhere in the middle

        $this->mockIfttt->method('trigger')->willReturn(true);
        $this->mockCrontab->method('addEntry');
        // Called twice: once for cleanup, once for race condition protection
        $this->mockCrontab->expects($this->exactly(2))
            ->method('removeByPattern')
            ->with('HOTTUB:heat-target');

        $this->service->start($targetTempF);

        // Store initial reading (82°F)
        $this->storeReading($this->fixture->getReading(0));
        $this->service->checkAndAdjust();

        // Feed readings until we hit the target
        $readings = $this->fixture->getAllReadings();
        $targetReached = false;

        foreach ($readings as $reading) {
            $this->storeReading($reading);
            $result = $this->service->checkAndAdjust();

            if ($result['target_reached'] ?? false) {
                $targetReached = true;
                $this->assertEquals($reading['minute'], $this->fixture->getMinuteAtTemperature($targetTempF));
                break;
            }
        }

        $this->assertTrue($targetReached, 'Target should have been reached');
        $this->assertFalse($this->service->getState()['active']);
    }

    /**
     * Test that the fixture data matches expected values.
     */
    public function testFixtureDataIntegrity(): void
    {
        $metadata = $this->fixture->getMetadata();

        $this->assertEquals(82.0, $metadata['start_temp_f']);
        $this->assertEquals(103.5, $metadata['end_temp_f']);
        $this->assertEquals(0.5, $metadata['heating_rate_f_per_min']);

        // Verify first reading
        $first = $this->fixture->getReading(0);
        $this->assertEquals(82.0, $this->fixture->getWaterTempF($first));

        // Verify last reading
        $last = $this->fixture->getReading(43);
        $this->assertEquals(103.5, $this->fixture->getWaterTempF($last));

        // Verify we can find the 103.5°F reading
        $targetReading = $this->fixture->getFirstReadingAtOrAbove(103.5);
        $this->assertNotNull($targetReading);
        $this->assertEquals(43, $targetReading['minute']);
    }

    /**
     * Test that fixture data is stored and retrieved correctly.
     */
    public function testFixtureStorageAndRetrieval(): void
    {
        // Store the target reading (103.5°F)
        $targetReading = $this->fixture->getFirstReadingAtOrAbove(103.5);
        $apiRequest = $this->fixture->toApiRequest($targetReading);

        $this->esp32Temp->store($apiRequest);

        $latest = $this->esp32Temp->getLatest();

        // Verify the temp_f is correctly calculated
        // Original: 39.72°C = 103.5°F
        $this->assertNotNull($latest);
        $this->assertArrayHasKey('temp_f', $latest);
        $this->assertEqualsWithDelta(103.5, $latest['temp_f'], 0.1);
    }

    /**
     * Simple test: start, one low reading, one target reading.
     */
    public function testSimpleHeatingCycle(): void
    {
        $targetTempF = 103.5;

        $this->mockIfttt->method('trigger')->willReturn(true);
        $this->mockCrontab->method('addEntry');
        $this->mockCrontab->method('removeByPattern');

        // Start heating
        $this->service->start($targetTempF);
        $this->assertTrue($this->service->getState()['active']);

        // Store a low temperature reading
        $this->storeReading($this->fixture->getReading(0)); // 82°F
        $result = $this->service->checkAndAdjust();

        $this->assertTrue($result['heating']);
        $this->assertTrue($this->service->getState()['active']); // Still active

        // Store target temperature reading
        $this->storeReading($this->fixture->getFirstReadingAtOrAbove($targetTempF)); // 103.5°F
        $result = $this->service->checkAndAdjust();

        // Verify target was reached
        $this->assertArrayHasKey('target_reached', $result);
        $this->assertTrue($result['target_reached']);
        $this->assertFalse($this->service->getState()['active']); // Now inactive
    }

    /**
     * Test heating cycle with heater already on initially.
     */
    public function testHeatingCycleWithHeaterAlreadyOn(): void
    {
        $targetTempF = 103.5;

        // Heater is already on
        $this->equipmentStatus->setHeaterOn();

        // Should NOT trigger heater-on, but should still work
        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $this->mockCrontab->method('addEntry');
        $this->mockCrontab->method('removeByPattern');

        $this->service->start($targetTempF);

        // Start at 82°F
        $this->storeReading($this->fixture->getReading(0));
        $result = $this->service->checkAndAdjust();

        // Should NOT turn heater on again (already on)
        $this->assertFalse($result['heater_turned_on']);
        $this->assertTrue($result['heating']);

        // Jump to target
        $this->storeReading($this->fixture->getFirstReadingAtOrAbove($targetTempF));
        $result = $this->service->checkAndAdjust();

        $this->assertTrue($result['target_reached']);
        $this->assertTrue($result['heater_turned_off']);
    }
}
