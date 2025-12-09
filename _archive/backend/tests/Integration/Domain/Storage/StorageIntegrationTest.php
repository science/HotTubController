<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration\Domain\Storage;

use PHPUnit\Framework\TestCase;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use DateTime;

class StorageIntegrationTest extends TestCase
{
    private string $testStoragePath;
    private JsonStorageManager $storageManager;
    private HeatingCycleRepository $cycleRepository;
    private HeatingEventRepository $eventRepository;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir() . '/storage_integration_test_' . uniqid();
        $this->storageManager = new JsonStorageManager($this->testStoragePath);

        $this->cycleRepository = new HeatingCycleRepository($this->storageManager);
        $this->eventRepository = new HeatingEventRepository($this->storageManager);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testStoragePath);
    }

    public function testFullHeatingCycleWorkflow(): void
    {
        // 1. Create a new heating cycle
        $cycle = new HeatingCycle();
        $cycle->setTargetTemp(104.0);
        $cycle->setCurrentTemp(88.5);
        $cycle->setStatus(HeatingCycle::STATUS_HEATING);
        $cycle->setEstimatedCompletion(new DateTime('+2 hours'));

        $this->assertTrue($this->cycleRepository->save($cycle));
        $cycleId = $cycle->getId();

        // 2. Create monitoring events for the cycle
        $monitorEvent1 = new HeatingEvent();
        $monitorEvent1->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $monitorEvent1->setScheduledFor(new DateTime('+15 minutes'));
        $monitorEvent1->setCycleId($cycleId);
        $monitorEvent1->setTargetTemp(104.0);

        $this->assertTrue($this->eventRepository->save($monitorEvent1));

        $monitorEvent2 = new HeatingEvent();
        $monitorEvent2->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $monitorEvent2->setScheduledFor(new DateTime('+30 minutes'));
        $monitorEvent2->setCycleId($cycleId);
        $monitorEvent2->setTargetTemp(104.0);

        $this->assertTrue($this->eventRepository->save($monitorEvent2));

        // 3. Verify cycle was saved and can be retrieved
        $allData = $this->cycleRepository->getData();
        $this->assertNotEmpty($allData, 'No data found in repository');

        $foundCycle = $this->cycleRepository->find($cycleId);
        $this->assertNotNull($foundCycle, "Could not find cycle with ID: {$cycleId}");
        $this->assertEquals(104.0, $foundCycle->getTargetTemp());
        $this->assertEquals(88.5, $foundCycle->getCurrentTemp());
        $this->assertTrue($foundCycle->isActive());

        // 4. Find active cycles
        $activeCycles = $this->cycleRepository->findActiveCycles();
        $this->assertCount(1, $activeCycles);
        $this->assertEquals($cycleId, $activeCycles[0]->getId());

        // 5. Find monitor events for the cycle
        $cycleEvents = $this->eventRepository->findEventsByCycle($cycleId);
        $this->assertCount(2, $cycleEvents);

        foreach ($cycleEvents as $event) {
            $this->assertEquals(HeatingEvent::EVENT_TYPE_MONITOR, $event->getEventType());
            $this->assertEquals($cycleId, $event->getCycleId());
            $this->assertTrue($event->isScheduled());
        }

        // 6. Update cycle temperature and complete it
        $foundCycle->setCurrentTemp(104.0);
        $foundCycle->setStatus(HeatingCycle::STATUS_COMPLETED);
        $this->assertTrue($this->cycleRepository->save($foundCycle));

        // 7. Cancel remaining monitor events
        $cancelledCount = $this->eventRepository->cancelEventsByCycle($cycleId);
        $this->assertEquals(2, $cancelledCount);

        // 8. Verify cycle is no longer active
        $activeCycles = $this->cycleRepository->findActiveCycles();
        $this->assertCount(0, $activeCycles);

        $completedCycles = $this->cycleRepository->findCompletedCycles();
        $this->assertCount(1, $completedCycles);
        $this->assertEquals($cycleId, $completedCycles[0]->getId());
    }

    public function testScheduledEventManagement(): void
    {
        // Create multiple start events
        $event1 = new HeatingEvent();
        $event1->setEventType(HeatingEvent::EVENT_TYPE_START);
        $event1->setScheduledFor(new DateTime('+1 hour'));
        $event1->setTargetTemp(102.0);
        $this->assertTrue($this->eventRepository->save($event1));

        $event2 = new HeatingEvent();
        $event2->setEventType(HeatingEvent::EVENT_TYPE_START);
        $event2->setScheduledFor(new DateTime('+2 hours'));
        $event2->setTargetTemp(104.0);
        $this->assertTrue($this->eventRepository->save($event2));

        $event3 = new HeatingEvent();
        $event3->setEventType(HeatingEvent::EVENT_TYPE_START);
        $event3->setScheduledFor(new DateTime('-1 hour')); // Past due
        $event3->setTargetTemp(106.0);

        // This should trigger validation error for past schedule
        $errors = $event3->validate();
        $this->assertNotEmpty($errors);

        // Fix the time and save
        $event3->setScheduledFor(new DateTime('+3 hours'));
        $this->assertEmpty($event3->validate());
        $this->assertTrue($this->eventRepository->save($event3));

        // Test queries
        $startEvents = $this->eventRepository->findStartEvents();
        $this->assertCount(3, $startEvents);

        $scheduledEvents = $this->eventRepository->findScheduledEvents();
        $this->assertCount(3, $scheduledEvents);

        // Test ordering (should be chronological)
        $this->assertTrue($scheduledEvents[0]->getScheduledFor() <= $scheduledEvents[1]->getScheduledFor());
        $this->assertTrue($scheduledEvents[1]->getScheduledFor() <= $scheduledEvents[2]->getScheduledFor());

        // Cancel all start events
        $cancelled = $this->eventRepository->cancelAllStartEvents();
        $this->assertEquals(3, $cancelled);

        $remainingScheduled = $this->eventRepository->findScheduledEvents();
        $this->assertCount(0, $remainingScheduled);
    }

    public function testQueryBuilderIntegration(): void
    {
        // Create test data
        $cycles = [];
        $temps = [98.5, 102.0, 104.5, 106.0, 95.0];

        foreach ($temps as $i => $temp) {
            $cycle = new HeatingCycle();
            $cycle->setTargetTemp($temp);
            $cycle->setCurrentTemp($temp - 5);
            $cycle->setStatus($i % 2 === 0 ? HeatingCycle::STATUS_HEATING : HeatingCycle::STATUS_COMPLETED);
            $this->assertTrue($this->cycleRepository->save($cycle));
            $cycles[] = $cycle;
        }

        // Test complex queries
        $heatingCycles = $this->cycleRepository->query()
            ->where('status', HeatingCycle::STATUS_HEATING)
            ->orderBy('target_temp', 'desc')
            ->get();

        $this->assertCount(3, $heatingCycles); // Indices 0, 2, 4
        $this->assertEquals(104.5, $heatingCycles[0]->getTargetTemp());
        $this->assertEquals(98.5, $heatingCycles[1]->getTargetTemp());
        $this->assertEquals(95.0, $heatingCycles[2]->getTargetTemp());

        // Test temperature range queries
        $midTempCycles = $this->cycleRepository->query()
            ->whereBetween('target_temp', [100.0, 105.0])
            ->count();

        $this->assertEquals(2, $midTempCycles); // 102.0 and 104.5

        // Test metadata queries
        $cycles[0]->addMetadata('priority', 'high');
        $this->cycleRepository->save($cycles[0]);

        $highPriorityCycles = $this->cycleRepository->query()
            ->where('metadata.priority', 'high')
            ->get();

        $this->assertCount(1, $highPriorityCycles);
        $this->assertEquals($cycles[0]->getId(), $highPriorityCycles[0]->getId());
    }

    public function testFileRotationAndCleanup(): void
    {
        // Create storage manager with aggressive rotation for testing
        $rotatingStorage = new JsonStorageManager($this->testStoragePath . '/rotating', [
            'rotation' => [
                'strategy' => 'size',
                'max_size' => 1024, // 1KB
                'retention_days' => 1,
                'compress_after_days' => 1,
            ]
        ]);

        $cycleRepo = new HeatingCycleRepository($rotatingStorage);

        // Create many cycles to trigger rotation
        for ($i = 0; $i < 50; $i++) {
            $cycle = new HeatingCycle();
            $cycle->setTargetTemp(104.0);
            $cycle->setCurrentTemp(90.0 + $i);
            $cycle->addMetadata('iteration', $i);
            $cycle->addMetadata('large_data', str_repeat('x', 100)); // Add bulk to trigger size limit
            $this->assertTrue($cycleRepo->save($cycle));
        }

        // Verify all cycles were saved
        $this->assertEquals(50, $cycleRepo->count());

        // All cycles should still be retrievable
        $allCycles = $cycleRepo->findAll();
        $this->assertCount(50, $allCycles);

        // Test that we can query across all data
        $highTempCycles = $cycleRepo->query()
            ->where('current_temp', '>', 130.0)
            ->count();

        $this->assertGreaterThan(0, $highTempCycles);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $path . '/' . $file;
            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        rmdir($path);
    }
}
