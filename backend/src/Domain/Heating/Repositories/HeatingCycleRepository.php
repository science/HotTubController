<?php

declare(strict_types=1);

namespace HotTubController\Domain\Heating\Repositories;

use HotTubController\Domain\Storage\Repository;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Infrastructure\Storage\JsonStorageManager;

class HeatingCycleRepository extends Repository
{
    public function __construct(JsonStorageManager $storageManager)
    {
        parent::__construct($storageManager);
    }

    public function findById(string $id): ?HeatingCycle
    {
        return $this->find($id);
    }

    public function findActiveCycles(): array
    {
        return $this->query()
            ->where('status', HeatingCycle::STATUS_HEATING)
            ->orderBy('started_at', 'desc')
            ->get();
    }

    public function findRecentCycles(int $hours = 24): array
    {
        $since = new \DateTime("-{$hours} hours");
        
        return $this->query()
            ->where('started_at', '>=', $since->format('Y-m-d H:i:s'))
            ->orderBy('started_at', 'desc')
            ->get();
    }

    public function findByStatus(string $status): array
    {
        return $this->query()
            ->where('status', $status)
            ->orderBy('started_at', 'desc')
            ->get();
    }

    public function findCompletedCycles(int $limit = 50): array
    {
        return $this->query()
            ->whereIn('status', [HeatingCycle::STATUS_COMPLETED, HeatingCycle::STATUS_STOPPED])
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findByTargetTemperature(float $targetTemp): array
    {
        return $this->query()
            ->where('target_temp', $targetTemp)
            ->orderBy('started_at', 'desc')
            ->get();
    }

    public function findLongRunningCycles(int $hours = 4): array
    {
        $cutoff = new \DateTime("-{$hours} hours");
        
        return $this->query()
            ->where('status', HeatingCycle::STATUS_HEATING)
            ->where('started_at', '<=', $cutoff->format('Y-m-d H:i:s'))
            ->orderBy('started_at', 'asc')
            ->get();
    }

    public function getAverageHeatingTime(float $targetTemp): ?float
    {
        $cycles = $this->query()
            ->where('target_temp', $targetTemp)
            ->where('status', HeatingCycle::STATUS_COMPLETED)
            ->whereNotNull('started_at')
            ->whereNotNull('updated_at')
            ->get();

        if (empty($cycles)) {
            return null;
        }

        $totalSeconds = 0;
        $count = 0;

        foreach ($cycles as $cycle) {
            $startTime = $cycle->getStartedAt()->getTimestamp();
            $endTime = $cycle->getUpdatedAt()?->getTimestamp();
            
            if ($endTime && $endTime > $startTime) {
                $totalSeconds += ($endTime - $startTime);
                $count++;
            }
        }

        return $count > 0 ? $totalSeconds / $count : null;
    }

    public function stopAllActiveCycles(): int
    {
        $activeCycles = $this->findActiveCycles();
        $stopped = 0;
        
        foreach ($activeCycles as $cycle) {
            $cycle->setStatus(HeatingCycle::STATUS_STOPPED);
            if ($this->save($cycle)) {
                $stopped++;
            }
        }
        
        return $stopped;
    }

    protected function getStorageKey(): string
    {
        return HeatingCycle::getStorageKey();
    }

    protected function getModelClass(): string
    {
        return HeatingCycle::class;
    }
}