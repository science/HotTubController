<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\HeaterControlService;
use HotTub\Services\TargetTemperatureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Session lease / reaping.
 *
 * Regression cover for the 2026-08-22 incident: a heat-to-target session was
 * left {"active": true} after its monitoring cron chain was severed by an
 * outage, and every later heat request — the Heat button and the 06:55
 * scheduled job alike — returned 409 "Heat-to-target is already active".
 *
 * A session is only genuinely active while something is still tending it.
 * These tests pin that down.
 */
class TargetTemperatureLeaseTest extends TestCase
{
    private string $tmpDir;
    private string $stateFile;
    private string $equipmentStatusFile;
    private string $esp32TempFile;
    private string $equipmentEventLogFile;
    private MockObject&IftttClientInterface $mockIfttt;
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private EquipmentStatusService $equipmentStatus;
    private HeaterControlService $heaterControl;
    private Esp32TemperatureService $esp32Temp;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lease-test-' . uniqid();
        mkdir($this->tmpDir . '/state', 0755, true);
        mkdir($this->tmpDir . '/logs', 0755, true);

        $this->stateFile = $this->tmpDir . '/state/target-temperature.json';
        $this->equipmentStatusFile = $this->tmpDir . '/state/equipment-status.json';
        $this->esp32TempFile = $this->tmpDir . '/state/esp32-temperature.json';
        $this->equipmentEventLogFile = $this->tmpDir . '/logs/equipment-events.log';

        $this->mockIfttt = $this->createMock(IftttClientInterface::class);
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
        $this->heaterControl = new HeaterControlService($this->mockIfttt, $this->equipmentStatus);
        $this->esp32Temp = new Esp32TemperatureService($this->esp32TempFile, $this->equipmentStatus);
    }

    protected function tearDown(): void
    {
        foreach (['state', 'logs', 'scheduled-jobs'] as $subdir) {
            $dir = $this->tmpDir . '/' . $subdir;
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
        @rmdir($this->tmpDir);
    }

    private function createService(): TargetTemperatureService
    {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->heaterControl,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            null, // esp32Config
            null, // cronSchedulingService
            null, // heatTargetSettings
            null, // stallEventFile
            $this->equipmentEventLogFile
        );
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function iso(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format('c');
    }

    private function storeEsp32Reading(float $tempF): void
    {
        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => ($tempF - 32) * 5 / 9, 'temp_f' => $tempF],
            ],
            'uptime_seconds' => 3600,
        ]);
    }

    // ========== start() self-heals a wedged session ==========

    /**
     * THE incident. A session whose lease has run out must not block a new heat.
     */
    public function testStartSucceedsWhenPreviousSessionLeaseHasExpired(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-3 hours'),
            'lease_expires_at' => $this->iso('-2 hours'),
        ]);

        $service = $this->createService();
        $service->start(103.0);

        $state = $service->getState();
        $this->assertTrue($state['active'], 'A fresh session should be running');
        $this->assertEquals(103.0, $state['target_temp_f'], 'The new target should have replaced the stale one');
    }

    public function testStartStillRejectsWhenLeaseIsLive(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-5 minutes'),
            'lease_expires_at' => $this->iso('+10 minutes'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Heat-to-target is already active');

        $this->createService()->start(103.0);
    }

    // ========== Sessions written before the lease existed ==========

    /**
     * The upgrade path: anything already stuck on disk when this deploys has no
     * lease field at all. It must still be reapable, or the fix doesn't land.
     */
    public function testLegacySessionWithoutLeaseIsReapedAfterFallbackWindow(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-2 hours'),
        ]);

        $service = $this->createService();
        $service->start(103.0);

        $this->assertEquals(103.0, $service->getState()['target_temp_f']);
    }

    public function testLegacySessionWithoutLeaseIsNotReapedWithinFallbackWindow(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-10 minutes'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->createService()->start(103.0);
    }

    // ========== reapIfExpired() ==========

    public function testReapIfExpiredReturnsNullWhenNoSessionExists(): void
    {
        $this->assertNull($this->createService()->reapIfExpired());
    }

    public function testReapIfExpiredReturnsNullForLiveSession(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-5 minutes'),
            'lease_expires_at' => $this->iso('+10 minutes'),
        ]);

        $this->assertNull($this->createService()->reapIfExpired());
        $this->assertTrue($this->createService()->getState()['active'], 'A live session must survive');
    }

    public function testReapIfExpiredClearsStateAndReportsTheReason(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-3 hours'),
            'lease_expires_at' => $this->iso('-2 hours'),
        ]);

        $record = $this->createService()->reapIfExpired();

        $this->assertIsArray($record);
        $this->assertEquals('lease_expired', $record['reason']);
        $this->assertEquals(102.0, $record['target_temp_f']);
        $this->assertFalse($this->createService()->getState()['active']);
    }

    /**
     * An orphaned session may have left the heater energised — reaping must not
     * silently walk away from that.
     */
    public function testReapCommandsHeaterOffWhenEquipmentBelievesHeaterOn(): void
    {
        $this->equipmentStatus->setHeaterOn();

        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-3 hours'),
            'lease_expires_at' => $this->iso('-2 hours'),
        ]);

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $this->createService()->reapIfExpired();
    }

    public function testReapLogsSessionExpiredToTheEquipmentEventLog(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-3 hours'),
            'lease_expires_at' => $this->iso('-2 hours'),
        ]);

        $this->createService()->reapIfExpired();

        $this->assertFileExists($this->equipmentEventLogFile);
        $this->assertStringContainsString('session_expired', file_get_contents($this->equipmentEventLogFile));
    }

    /**
     * A lease we can't read is not a lease. Failing toward "expired" keeps the
     * session recoverable; failing the other way would reproduce the very wedge
     * this mechanism exists to prevent — and would do it permanently.
     */
    public function testUnparseableLeaseIsTreatedAsExpiredRatherThanBlockingForever(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => $this->iso('-30 minutes'),
            'lease_expires_at' => 'not-a-date',
        ]);

        $service = $this->createService();
        $record = $service->reapIfExpired();

        $this->assertIsArray($record, 'An unreadable lease must be reaped, not trusted');
        $this->assertFalse($service->getState()['active']);
    }

    public function testUnparseableStartedAtIsTreatedAsExpired(): void
    {
        $this->writeState([
            'active' => true,
            'target_temp_f' => 102.0,
            'started_at' => 'garbage',
        ]);

        $this->assertIsArray($this->createService()->reapIfExpired());
    }

    // ========== The lease is renewed by the check that schedules the successor ==========

    public function testCheckAndAdjustStampsLeaseBeyondTheScheduledNextCheck(): void
    {
        $this->storeEsp32Reading(95.0);

        $service = $this->createService();
        $service->start(103.0);

        $state = $service->getState();
        $this->assertArrayHasKey('next_check_at', $state);
        $this->assertArrayHasKey('lease_expires_at', $state);

        $nextCheck = (new \DateTimeImmutable($state['next_check_at']))->getTimestamp();
        $leaseEnds = (new \DateTimeImmutable($state['lease_expires_at']))->getTimestamp();

        $this->assertGreaterThan(time(), $leaseEnds, 'A freshly stamped lease must be in the future');
        $this->assertEquals(
            TargetTemperatureService::SESSION_LEASE_GRACE_SECONDS,
            $leaseEnds - $nextCheck,
            'The lease should outlive the scheduled check by exactly the grace period'
        );
    }

    /**
     * A session that keeps checking in keeps its claim — renewal must actually move
     * the lease forward, otherwise a long heat would reap itself mid-session.
     */
    public function testLeaseIsPushedForwardOnEachCheck(): void
    {
        $this->storeEsp32Reading(95.0);

        $service = $this->createService();
        $service->start(103.0);

        // Shorten the lease as if it were nearly up, then check in again.
        $state = $service->getState();
        $shortLease = $this->iso('+1 minute');
        $state['lease_expires_at'] = $shortLease;
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));

        $service->checkAndAdjust();

        $this->assertGreaterThan(
            (new \DateTimeImmutable($shortLease))->getTimestamp(),
            (new \DateTimeImmutable($service->getState()['lease_expires_at']))->getTimestamp(),
            'Checking in should push the lease further out'
        );
    }
}
