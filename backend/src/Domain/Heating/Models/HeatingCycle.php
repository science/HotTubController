<?php

declare(strict_types=1);

namespace HotTubController\Domain\Heating\Models;

use HotTubController\Domain\Storage\Model;
use HotTubController\Domain\Storage\RepositoryInterface;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use DateTime;

class HeatingCycle extends Model
{
    public const STATUS_HEATING = 'heating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ERROR = 'error';

    private DateTime $startedAt;
    private float $targetTemp;
    private ?float $currentTemp = null;
    private string $status = self::STATUS_HEATING;
    private ?DateTime $estimatedCompletion = null;
    private ?DateTime $lastCheck = null;
    private array $metadata = [];

    public function __construct(?string $id = null)
    {
        parent::__construct($id);
        $this->startedAt = new DateTime();
    }

    public function getStartedAt(): DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTime $startedAt): self
    {
        $this->startedAt = $startedAt;
        $this->markAsUpdated();
        return $this;
    }

    public function getTargetTemp(): float
    {
        return $this->targetTemp;
    }

    public function setTargetTemp(float $targetTemp): self
    {
        $this->targetTemp = $targetTemp;
        $this->markAsUpdated();
        return $this;
    }

    public function getCurrentTemp(): ?float
    {
        return $this->currentTemp;
    }

    public function setCurrentTemp(?float $currentTemp): self
    {
        $this->currentTemp = $currentTemp;
        $this->lastCheck = new DateTime();
        $this->markAsUpdated();
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $validStatuses = [
            self::STATUS_HEATING,
            self::STATUS_COMPLETED,
            self::STATUS_STOPPED,
            self::STATUS_ERROR,
        ];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->status = $status;
        $this->markAsUpdated();
        return $this;
    }

    public function getEstimatedCompletion(): ?DateTime
    {
        return $this->estimatedCompletion;
    }

    public function setEstimatedCompletion(?DateTime $estimatedCompletion): self
    {
        $this->estimatedCompletion = $estimatedCompletion;
        $this->markAsUpdated();
        return $this;
    }

    public function getLastCheck(): ?DateTime
    {
        return $this->lastCheck;
    }

    public function setLastCheck(?DateTime $lastCheck): self
    {
        $this->lastCheck = $lastCheck;
        $this->markAsUpdated();
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        $this->markAsUpdated();
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        $this->markAsUpdated();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_HEATING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_STOPPED]);
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function getTemperatureDifference(): ?float
    {
        if ($this->currentTemp === null) {
            return null;
        }

        return $this->targetTemp - $this->currentTemp;
    }

    public function getElapsedTime(): int
    {
        $now = new DateTime();
        return $now->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function getEstimatedTimeRemaining(): ?int
    {
        if ($this->estimatedCompletion === null) {
            return null;
        }

        $now = new DateTime();
        $remaining = $this->estimatedCompletion->getTimestamp() - $now->getTimestamp();
        
        return max(0, $remaining);
    }

    protected function getModelData(): array
    {
        return [
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'target_temp' => $this->targetTemp,
            'current_temp' => $this->currentTemp,
            'status' => $this->status,
            'estimated_completion' => $this->estimatedCompletion?->format('Y-m-d H:i:s'),
            'last_check' => $this->lastCheck?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
        ];
    }

    protected function setModelData(array $data): void
    {
        $this->startedAt = new DateTime($data['started_at'] ?? 'now');
        $this->targetTemp = (float)($data['target_temp'] ?? 104.0);
        $this->currentTemp = isset($data['current_temp']) ? (float)$data['current_temp'] : null;
        $this->status = $data['status'] ?? self::STATUS_HEATING;
        $this->estimatedCompletion = isset($data['estimated_completion']) ? 
            new DateTime($data['estimated_completion']) : null;
        $this->lastCheck = isset($data['last_check']) ? 
            new DateTime($data['last_check']) : null;
        $this->metadata = $data['metadata'] ?? [];
    }


    public function validate(): array
    {
        $errors = [];

        if (!isset($this->targetTemp) || $this->targetTemp <= 0) {
            $errors[] = 'Target temperature must be greater than 0';
        }

        if ($this->targetTemp > 110) {
            $errors[] = 'Target temperature cannot exceed 110°F for safety';
        }

        if ($this->currentTemp !== null && $this->currentTemp < 0) {
            $errors[] = 'Current temperature cannot be negative';
        }

        if ($this->estimatedCompletion !== null && $this->estimatedCompletion < $this->startedAt) {
            $errors[] = 'Estimated completion cannot be before start time';
        }

        return $errors;
    }

    public static function getStorageKey(): string
    {
        return 'heating_cycles_rotated';
    }
}