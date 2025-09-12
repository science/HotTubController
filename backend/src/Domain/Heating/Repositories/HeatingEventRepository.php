<?php

declare(strict_types=1);

namespace HotTubController\Domain\Heating\Repositories;

use HotTubController\Domain\Storage\Repository;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use DateTime;

class HeatingEventRepository extends Repository
{
    public function __construct(JsonStorageManager $storageManager)
    {
        parent::__construct($storageManager);
    }

    public function findScheduledEvents(): array
    {
        return $this->query()
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findStartEvents(): array
    {
        return $this->query()
            ->where('event_type', HeatingEvent::EVENT_TYPE_START)
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findMonitorEvents(): array
    {
        return $this->query()
            ->where('event_type', HeatingEvent::EVENT_TYPE_MONITOR)
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findEventsByCycle(string $cycleId): array
    {
        return $this->query()
            ->where('cycle_id', $cycleId)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findPastDueEvents(): array
    {
        $now = new DateTime();

        return $this->query()
            ->where('scheduled_for', '<=', $now->format('Y-m-d H:i:s'))
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findUpcomingEvents(int $hours = 24): array
    {
        $now = new DateTime();
        $future = new DateTime("+{$hours} hours");

        return $this->query()
            ->whereBetween('scheduled_for', [
                $now->format('Y-m-d H:i:s'),
                $future->format('Y-m-d H:i:s')
            ])
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function findTriggeredEvents(int $hours = 24): array
    {
        $since = new DateTime("-{$hours} hours");

        return $this->query()
            ->where('status', HeatingEvent::STATUS_TRIGGERED)
            ->where('updated_at', '>=', $since->format('Y-m-d H:i:s'))
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function findByTargetTemperature(float $targetTemp): array
    {
        return $this->query()
            ->where('target_temp', $targetTemp)
            ->orderBy('scheduled_for', 'desc')
            ->get();
    }

    public function findByTimeRange(DateTime $start, DateTime $end, ?string $status = null, ?string $eventType = null): array
    {
        $query = $this->query()
            ->whereBetween('scheduled_for', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s')
            ]);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return $query
            ->orderBy('scheduled_for', 'asc')
            ->get();
    }

    public function cancelAllStartEvents(): int
    {
        $startEvents = $this->findStartEvents();
        $cancelled = 0;

        foreach ($startEvents as $event) {
            if ($event->isScheduled()) {
                $event->cancel();
                $this->save($event);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    public function cancelEventsByCycle(string $cycleId): int
    {
        $cycleEvents = $this->findEventsByCycle($cycleId);
        $cancelled = 0;

        foreach ($cycleEvents as $event) {
            if ($event->isScheduled()) {
                $event->cancel();
                $this->save($event);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    public function cancelMonitorEvents(): int
    {
        $monitorEvents = $this->findMonitorEvents();
        $cancelled = 0;

        foreach ($monitorEvents as $event) {
            if ($event->isScheduled()) {
                $event->cancel();
                $this->save($event);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    public function findById(string $id): ?HeatingEvent
    {
        return $this->find($id);
    }

    public function getNextScheduledEvent(?string $eventType = null): ?HeatingEvent
    {
        $query = $this->query()
            ->where('status', HeatingEvent::STATUS_SCHEDULED);

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return $query
            ->orderBy('scheduled_for', 'asc')
            ->first();
    }

    public function countScheduledEvents(): int
    {
        return $this->query()
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->count();
    }

    public function countEventsByType(string $eventType): int
    {
        return $this->query()
            ->where('event_type', $eventType)
            ->where('status', HeatingEvent::STATUS_SCHEDULED)
            ->count();
    }

    public function cleanupOldEvents(int $days = 30): int
    {
        $cutoff = new DateTime("-{$days} days");
        $oldEvents = $this->query()
            ->whereIn('status', [HeatingEvent::STATUS_TRIGGERED, HeatingEvent::STATUS_CANCELLED])
            ->where('updated_at', '<=', $cutoff->format('Y-m-d H:i:s'))
            ->get();

        $deleted = 0;
        foreach ($oldEvents as $event) {
            if ($this->delete($event->getId())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    protected function getStorageKey(): string
    {
        return HeatingEvent::getStorageKey();
    }

    protected function getModelClass(): string
    {
        return HeatingEvent::class;
    }
}
