<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\HeaterControlService;
use HotTub\Services\HeatTargetSettingsService;
use HotTub\Services\TargetTemperatureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TargetTemperatureStallDetectionTest extends TestCase
{
    private string $stateFile;
    private string $equipmentStatusFile;
    private string $esp32TempFile;
    private string $settingsFile;
    private string $stallEventFile;
    private string $equipmentEventLogFile;
    private MockObject&IftttClientInterface $mockIfttt;
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private EquipmentStatusService $equipmentStatus;
    private HeaterControlService $heaterControl;
    private Esp32TemperatureService $esp32Temp;
    private HeatTargetSettingsService $heatTargetSettings;

    protected function setUp(): void
    {
        $uid = uniqid();
        $tmpDir = sys_get_temp_dir() . '/stall-test-' . $uid;
        mkdir($tmpDir, 0755, true);
        mkdir($tmpDir . '/state', 0755, true);
        mkdir($tmpDir . '/logs', 0755, true);

        $this->stateFile = $tmpDir . '/state/target-temperature.json';
        $this->equipmentStatusFile = $tmpDir . '/state/equipment-status.json';
        $this->esp32TempFile = $tmpDir . '/state/esp32-temperature.json';
        $this->settingsFile = $tmpDir . '/state/heat-target-settings.json';
        $this->stallEventFile = $tmpDir . '/state/last-stall-event.json';
        $this->equipmentEventLogFile = $tmpDir . '/logs/equipment-events.log';

        $this->mockIfttt = $this->createMock(IftttClientInterface::class);
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
        $this->heaterControl = new HeaterControlService($this->mockIfttt, $this->equipmentStatus);
        $this->esp32Temp = new Esp32TemperatureService($this->esp32TempFile, $this->equipmentStatus);
        $this->heatTargetSettings = new HeatTargetSettingsService($this->settingsFile);
    }

    protected function tearDown(): void
    {
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        $files = [
            $this->stateFile, $this->equipmentStatusFile, $this->esp32TempFile,
            $this->settingsFile, $this->stallEventFile, $this->equipmentEventLogFile,
            $lockFile,
        ];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        // Clean up directories
        $tmpDir = dirname(dirname($this->stateFile));
        foreach (['state', 'logs', 'scheduled-jobs'] as $subdir) {
            $dir = $tmpDir . '/' . $subdir;
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
        @rmdir($tmpDir);
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
            $this->heatTargetSettings,
            $this->stallEventFile,
            $this->equipmentEventLogFile
        );
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

    /**
     * Write state file directly to simulate time-based scenarios.
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    // ==================== Grace Period Tests ====================

    /**
     * @test
     * No stall during grace period — heater stays on when no progress but within grace period.
     */
    public function noStallDuringGracePeriod(): void
    {
        $service = $this->createService();

        // Set up: active session started 5 minutes ago (within default 15-min grace period)
        $startedAt = (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
        ]);

        // Current temp well below target — no progress but within grace period
        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['active']);
        $this->assertTrue($result['heating']);
        $this->assertArrayNotHasKey('stall_detected', $result);
    }

    // ==================== Stall Detection Tests ====================

    /**
     * @test
     * Stall detected after grace period when temp hasn't risen.
     */
    public function stallDetectedAfterGracePeriodWithNoProgress(): void
    {
        $service = $this->createService();

        // Set up: session started 20 minutes ago (past 15-min grace), stall ref set 10 min ago (past 5-min timeout)
        $startedAt = (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        // Current temp same as stall reference — no progress
        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        // Expect IFTTT to turn off heater
        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off');

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['stall_detected']);
        $this->assertFalse($result['active']);
        $this->assertStringContainsString('stall', $result['error']);
    }

    /**
     * @test
     * Progress resets stall timer — temperature increase resets the stall reference.
     */
    public function progressResetsStallReference(): void
    {
        $service = $this->createService();

        // Set up: session started 20 minutes ago, stall ref set 10 min ago at 90°F
        $startedAt = (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        // Current temp higher than stall reference — progress!
        $this->storeEsp32Reading(91.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['active']);
        $this->assertTrue($result['heating']);
        $this->assertArrayNotHasKey('stall_detected', $result);

        // Verify stall reference was updated in state
        $state = $service->getState();
        $this->assertEquals(91.0, $state['stall_reference_temp_f']);
    }

    /**
     * @test
     * Stall after initial progress — temp rises then plateaus.
     */
    public function stallAfterPlateau(): void
    {
        $service = $this->createService();

        // Set up: session started 30 min ago, last progress was at 95°F 10 min ago
        $startedAt = (new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 95.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        // Temp still at 95°F — no further progress
        $this->storeEsp32Reading(95.0);
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off');

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['stall_detected']);
        $this->assertFalse($result['active']);
    }

    /**
     * @test
     * Normal heating unaffected — reaching target still works.
     */
    public function normalHeatingStillReachesTarget(): void
    {
        $service = $this->createService();

        // Set up: active session
        $startedAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 100.0,
            'stall_reference_at' => $startedAt,
        ]);

        // Current temp at target — should stop normally
        $this->storeEsp32Reading(103.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertFalse($result['active']);
        $this->assertTrue($result['target_reached']);
        $this->assertArrayNotHasKey('stall_detected', $result);
    }

    /**
     * @test
     * Stall initializes reference fields when they don't exist.
     */
    public function initializesStallReferenceOnFirstCheck(): void
    {
        $service = $this->createService();

        // Active session without stall reference fields (e.g., session started before upgrade)
        $startedAt = (new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
        ]);

        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['active']);
        $this->assertTrue($result['heating']);

        // Verify stall reference was initialized
        $state = $service->getState();
        $this->assertEquals(90.0, $state['stall_reference_temp_f']);
        $this->assertArrayHasKey('stall_reference_at', $state);
    }

    // ==================== Event Logging Tests ====================

    /**
     * @test
     * Stall writes to equipment event log with stall_detected action.
     */
    public function stallLogsToEquipmentEventLog(): void
    {
        $service = $this->createService();

        $startedAt = (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $service->checkAndAdjust();

        $this->assertFileExists($this->equipmentEventLogFile);
        $logContent = file_get_contents($this->equipmentEventLogFile);
        $lines = array_filter(explode("\n", trim($logContent)));
        $lastLine = json_decode(end($lines), true);

        $this->assertEquals('heater', $lastLine['equipment']);
        $this->assertEquals('stall_detected', $lastLine['action']);
        $this->assertArrayHasKey('water_temp_f', $lastLine);
    }

    /**
     * @test
     * Stall writes last-stall-event.json with details.
     */
    public function stallWritesStallEventFile(): void
    {
        $service = $this->createService();

        $startedAt = (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $service->checkAndAdjust();

        $this->assertFileExists($this->stallEventFile);
        $stallEvent = json_decode(file_get_contents($this->stallEventFile), true);

        $this->assertArrayHasKey('timestamp', $stallEvent);
        $this->assertEquals(90.0, $stallEvent['current_temp_f']);
        $this->assertEquals(103.0, $stallEvent['target_temp_f']);
        $this->assertArrayHasKey('reason', $stallEvent);
    }

    /**
     * @test
     * Starting new session clears stall event file.
     */
    public function startClearsStallEventFile(): void
    {
        // Create a stall event file
        $dir = dirname($this->stallEventFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->stallEventFile, json_encode([
            'timestamp' => date('c'),
            'current_temp_f' => 90.0,
            'target_temp_f' => 103.0,
            'reason' => 'Temperature not rising',
        ]));

        $this->assertFileExists($this->stallEventFile);

        // Start a new session
        $service = $this->createService();
        $this->storeEsp32Reading(85.0);
        $service->start(103.0);

        $this->assertFileDoesNotExist($this->stallEventFile);
    }

    // ==================== Custom Settings Tests ====================

    /**
     * @test
     * Custom grace period is respected.
     */
    public function customGracePeriodIsRespected(): void
    {
        // Set custom stall settings: 5 min grace, 3 min timeout
        $this->heatTargetSettings->updateStallSettings(5, 3);

        $service = $this->createService();

        // Session started 7 minutes ago (past 5-min grace), stall ref 4 min ago (past 3-min timeout)
        $startedAt = (new \DateTimeImmutable('-7 minutes', new \DateTimeZone('UTC')))->format('c');
        $stallRefAt = (new \DateTimeImmutable('-4 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $stallRefAt,
        ]);

        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['stall_detected']);
    }

    /**
     * @test
     * No stall when within custom grace period even with no progress.
     */
    public function noStallWithinCustomGracePeriod(): void
    {
        // Set long grace period: 30 min grace, 5 min timeout
        $this->heatTargetSettings->updateStallSettings(30, 5);

        $service = $this->createService();

        // Session started 20 minutes ago (within 30-min grace)
        $startedAt = (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('c');
        $this->writeState([
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
            'stall_reference_temp_f' => 90.0,
            'stall_reference_at' => $startedAt,
        ]);

        $this->storeEsp32Reading(90.0);
        $this->equipmentStatus->setHeaterOn();

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['active']);
        $this->assertTrue($result['heating']);
        $this->assertArrayNotHasKey('stall_detected', $result);
    }
}
