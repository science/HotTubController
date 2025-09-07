<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating\Models;

use PHPUnit\Framework\TestCase;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use DateTime;

class HeatingEventTest extends TestCase
{
    private HeatingEvent $event;

    protected function setUp(): void
    {
        $this->event = new HeatingEvent('test-event-123');
    }

    public function testInitialization(): void
    {
        $this->assertEquals('test-event-123', $this->event->getId());
        $this->assertInstanceOf(DateTime::class, $this->event->getScheduledFor());
        $this->assertEquals(HeatingEvent::EVENT_TYPE_START, $this->event->getEventType());
        $this->assertEquals(104.0, $this->event->getTargetTemp());
        $this->assertEquals(HeatingEvent::STATUS_SCHEDULED, $this->event->getStatus());
        $this->assertTrue($this->event->isScheduled());
        $this->assertTrue($this->event->isStartEvent());
    }

    public function testEventTypes(): void
    {
        // Start event
        $this->event->setEventType(HeatingEvent::EVENT_TYPE_START);
        $this->assertTrue($this->event->isStartEvent());
        $this->assertFalse($this->event->isMonitorEvent());

        // Monitor event
        $this->event->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $this->assertFalse($this->event->isStartEvent());
        $this->assertTrue($this->event->isMonitorEvent());
    }

    public function testInvalidEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->event->setEventType('invalid_type');
    }

    public function testStatusTransitions(): void
    {
        // Initially scheduled
        $this->assertTrue($this->event->isScheduled());
        $this->assertFalse($this->event->isTriggered());
        $this->assertFalse($this->event->isCancelled());
        $this->assertFalse($this->event->hasError());

        // Trigger event
        $this->event->setStatus(HeatingEvent::STATUS_TRIGGERED);
        $this->assertFalse($this->event->isScheduled());
        $this->assertTrue($this->event->isTriggered());

        // Cancel event
        $this->event->setStatus(HeatingEvent::STATUS_CANCELLED);
        $this->assertTrue($this->event->isCancelled());

        // Error state
        $this->event->setStatus(HeatingEvent::STATUS_ERROR);
        $this->assertTrue($this->event->hasError());
    }

    public function testInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->event->setStatus('invalid_status');
    }

    public function testScheduledFor(): void
    {
        $futureTime = new DateTime('+2 hours');
        $this->event->setScheduledFor($futureTime);
        
        $this->assertEquals($futureTime, $this->event->getScheduledFor());
    }

    public function testTargetTemperature(): void
    {
        $this->event->setTargetTemp(106.0);
        $this->assertEquals(106.0, $this->event->getTargetTemp());
    }

    public function testCronExpression(): void
    {
        $cronExpr = '0 6 * * *'; // Daily at 6 AM
        $this->event->setCronExpression($cronExpr);
        
        $this->assertEquals($cronExpr, $this->event->getCronExpression());
    }

    public function testCycleId(): void
    {
        $cycleId = 'cycle-456';
        $this->event->setCycleId($cycleId);
        
        $this->assertEquals($cycleId, $this->event->getCycleId());
    }

    public function testMetadata(): void
    {
        $metadata = ['retry_count' => 0, 'source' => 'web'];
        $this->event->setMetadata($metadata);
        
        $this->assertEquals($metadata, $this->event->getMetadata());
        
        $this->event->addMetadata('new_field', 'new_value');
        $this->assertEquals('new_value', $this->event->getMetadata()['new_field']);
    }

    public function testPastDueDetection(): void
    {
        // Future event should not be past due
        $futureTime = new DateTime('+1 hour');
        $this->event->setScheduledFor($futureTime);
        $this->assertFalse($this->event->isPastDue());

        // Past event should be past due (if still scheduled)
        $pastTime = new DateTime('-1 hour');
        $this->event->setScheduledFor($pastTime);
        $this->assertTrue($this->event->isPastDue());

        // Past event that's already triggered should not be "past due"
        $this->event->setStatus(HeatingEvent::STATUS_TRIGGERED);
        $this->assertFalse($this->event->isPastDue());
    }

    public function testTimeUntilExecution(): void
    {
        $futureTime = new DateTime('+3600 seconds'); // 1 hour from now
        $this->event->setScheduledFor($futureTime);
        
        $timeRemaining = $this->event->getTimeUntilExecution();
        $this->assertGreaterThan(3500, $timeRemaining); // Should be close to 3600
        $this->assertLessThan(3700, $timeRemaining);
    }

    public function testTimeUntilExecutionPastEvent(): void
    {
        $pastTime = new DateTime('-1 hour');
        $this->event->setScheduledFor($pastTime);
        
        $this->assertEquals(0, $this->event->getTimeUntilExecution());
    }

    public function testGenerateCronComment(): void
    {
        $this->event->setEventType(HeatingEvent::EVENT_TYPE_START);
        $comment = $this->event->generateCronComment();
        
        $this->assertEquals('HOT_TUB_START:test-event-123', $comment);

        $this->event->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $comment = $this->event->generateCronComment();
        
        $this->assertEquals('HOT_TUB_MONITOR:test-event-123', $comment);
    }

    public function testCancel(): void
    {
        // Can cancel scheduled event
        $this->event->cancel();
        $this->assertTrue($this->event->isCancelled());

        // Cannot cancel already triggered event - status should not change
        $this->event->setStatus(HeatingEvent::STATUS_TRIGGERED);
        $this->event->cancel();
        $this->assertTrue($this->event->isTriggered());
    }

    public function testTrigger(): void
    {
        // Can trigger scheduled event
        $this->event->trigger();
        $this->assertTrue($this->event->isTriggered());

        // Cannot trigger already cancelled event - status should not change
        $this->event->setStatus(HeatingEvent::STATUS_CANCELLED);
        $this->event->trigger();
        $this->assertTrue($this->event->isCancelled());
    }

    public function testToArray(): void
    {
        $futureTime = new DateTime('+1 hour');
        $this->event->setScheduledFor($futureTime);
        $this->event->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $this->event->setTargetTemp(102.0);
        $this->event->setCronExpression('0 * * * *');
        $this->event->setCycleId('cycle-789');
        
        $array = $this->event->toArray();
        
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('scheduled_for', $array);
        $this->assertArrayHasKey('event_type', $array);
        $this->assertArrayHasKey('target_temp', $array);
        $this->assertArrayHasKey('cron_expression', $array);
        $this->assertArrayHasKey('cycle_id', $array);
        
        $this->assertEquals('test-event-123', $array['id']);
        $this->assertEquals(HeatingEvent::EVENT_TYPE_MONITOR, $array['event_type']);
        $this->assertEquals(102.0, $array['target_temp']);
        $this->assertEquals('0 * * * *', $array['cron_expression']);
        $this->assertEquals('cycle-789', $array['cycle_id']);
    }

    public function testFromArray(): void
    {
        $data = [
            'id' => 'from-array-456',
            'created_at' => '2024-01-01 12:00:00',
            'scheduled_for' => '2024-01-01 18:00:00',
            'event_type' => HeatingEvent::EVENT_TYPE_MONITOR,
            'target_temp' => 106.0,
            'cron_expression' => '0 18 * * *',
            'status' => HeatingEvent::STATUS_TRIGGERED,
            'cycle_id' => 'cycle-999',
            'metadata' => ['source' => 'api']
        ];
        
        $event = new HeatingEvent();
        $event->fromArray($data);
        
        $this->assertEquals('from-array-456', $event->getId());
        $this->assertEquals(HeatingEvent::EVENT_TYPE_MONITOR, $event->getEventType());
        $this->assertEquals(106.0, $event->getTargetTemp());
        $this->assertEquals('0 18 * * *', $event->getCronExpression());
        $this->assertEquals(HeatingEvent::STATUS_TRIGGERED, $event->getStatus());
        $this->assertEquals('cycle-999', $event->getCycleId());
        $this->assertEquals(['source' => 'api'], $event->getMetadata());
    }

    public function testValidation(): void
    {
        // Valid event
        $futureTime = new DateTime('+1 hour');
        $this->event->setScheduledFor($futureTime);
        $this->event->setTargetTemp(104.0);
        
        $this->assertEmpty($this->event->validate());
    }

    public function testValidationInvalidTemperature(): void
    {
        $this->event->setTargetTemp(0);
        $errors = $this->event->validate();
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('greater than 0', $errors[0]);

        $this->event->setTargetTemp(115.0);
        $errors = $this->event->validate();
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exceed 110°F', $errors[0]);
    }

    public function testValidationPastSchedule(): void
    {
        $pastTime = new DateTime('-1 hour');
        $this->event->setScheduledFor($pastTime);
        $this->event->setTargetTemp(104.0);
        
        $errors = $this->event->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cannot schedule event in the past', $errors[0]);
    }

    public function testValidationMonitorEventWithoutCycle(): void
    {
        $futureTime = new DateTime('+1 hour');
        $this->event->setScheduledFor($futureTime);
        $this->event->setEventType(HeatingEvent::EVENT_TYPE_MONITOR);
        $this->event->setTargetTemp(104.0);
        
        $errors = $this->event->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Monitor events must have a cycle ID', $errors[0]);
    }

    public function testJsonSerialization(): void
    {
        $this->event->setTargetTemp(105.0);
        
        $json = json_encode($this->event);
        $this->assertIsString($json);
        
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('test-event-123', $decoded['id']);
        $this->assertEquals(105.0, $decoded['target_temp']);
    }

    public function testStorageKey(): void
    {
        $this->assertEquals('heating_events', HeatingEvent::getStorageKey());
    }
}