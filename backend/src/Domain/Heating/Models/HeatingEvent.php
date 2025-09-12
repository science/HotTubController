<?php

declare(strict_types=1);

namespace HotTubController\Domain\Heating\Models;

use HotTubController\Domain\Storage\Model;
use HotTubController\Domain\Storage\RepositoryInterface;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use DateTime;

class HeatingEvent extends Model
{
    public const EVENT_TYPE_START = 'start';
    public const EVENT_TYPE_MONITOR = 'monitor';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ERROR = 'error';

    private DateTime $scheduledFor;
    private string $eventType = self::EVENT_TYPE_START;
    private float $targetTemp = 104.0;
    private ?string $cronExpression = null;
    private string $status = self::STATUS_SCHEDULED;
    private ?string $cycleId = null;
    private array $metadata = [];

    public function __construct(?string $id = null)
    {
        parent::__construct($id);
        $this->scheduledFor = new DateTime();
    }

    public function getScheduledFor(): DateTime
    {
        return $this->scheduledFor;
    }

    public function setScheduledFor(DateTime $scheduledFor): self
    {
        $this->scheduledFor = $scheduledFor;
        $this->markAsUpdated();
        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $validTypes = [self::EVENT_TYPE_START, self::EVENT_TYPE_MONITOR];

        if (!in_array($eventType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid event type: {$eventType}");
        }

        $this->eventType = $eventType;
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

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): self
    {
        $this->cronExpression = $cronExpression;
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
            self::STATUS_SCHEDULED,
            self::STATUS_TRIGGERED,
            self::STATUS_CANCELLED,
            self::STATUS_ERROR,
        ];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->status = $status;
        $this->markAsUpdated();
        return $this;
    }

    public function getCycleId(): ?string
    {
        return $this->cycleId;
    }

    public function setCycleId(?string $cycleId): self
    {
        $this->cycleId = $cycleId;
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

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isTriggered(): bool
    {
        return $this->status === self::STATUS_TRIGGERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isStartEvent(): bool
    {
        return $this->eventType === self::EVENT_TYPE_START;
    }

    public function isMonitorEvent(): bool
    {
        return $this->eventType === self::EVENT_TYPE_MONITOR;
    }

    public function isPastDue(): bool
    {
        $now = new DateTime();
        return $this->scheduledFor <= $now && $this->status === self::STATUS_SCHEDULED;
    }

    public function getTimeUntilExecution(): int
    {
        $now = new DateTime();
        return max(0, $this->scheduledFor->getTimestamp() - $now->getTimestamp());
    }

    public function generateCronComment(): string
    {
        $type = strtoupper($this->eventType);
        return "HOT_TUB_{$type}:{$this->getId()}";
    }

    public function cancel(): void
    {
        if ($this->status === self::STATUS_SCHEDULED) {
            $this->setStatus(self::STATUS_CANCELLED);
        }
    }

    public function trigger(): void
    {
        if ($this->status === self::STATUS_SCHEDULED) {
            $this->setStatus(self::STATUS_TRIGGERED);
        }
    }

    protected function getModelData(): array
    {
        return [
            'scheduled_for' => $this->scheduledFor->format('Y-m-d H:i:s'),
            'event_type' => $this->eventType,
            'target_temp' => $this->targetTemp,
            'cron_expression' => $this->cronExpression,
            'status' => $this->status,
            'cycle_id' => $this->cycleId,
            'metadata' => $this->metadata,
        ];
    }

    protected function setModelData(array $data): void
    {
        $this->scheduledFor = new DateTime($data['scheduled_for'] ?? 'now');
        $this->eventType = $data['event_type'] ?? self::EVENT_TYPE_START;
        $this->targetTemp = (float)($data['target_temp'] ?? 104.0);
        $this->cronExpression = $data['cron_expression'] ?? null;
        $this->status = $data['status'] ?? self::STATUS_SCHEDULED;
        $this->cycleId = $data['cycle_id'] ?? null;
        $this->metadata = $data['metadata'] ?? [];
    }


    public function validate(): array
    {
        $errors = [];

        if ($this->targetTemp <= 0) {
            $errors[] = 'Target temperature must be greater than 0';
        }

        if ($this->targetTemp > 110) {
            $errors[] = 'Target temperature cannot exceed 110°F for safety';
        }

        $now = new DateTime();
        if ($this->scheduledFor < $now && $this->status === self::STATUS_SCHEDULED) {
            $errors[] = 'Cannot schedule event in the past';
        }

        if ($this->eventType === self::EVENT_TYPE_MONITOR && empty($this->cycleId)) {
            $errors[] = 'Monitor events must have a cycle ID';
        }

        return $errors;
    }

    public static function getStorageKey(): string
    {
        return 'heating_events';
    }
}
