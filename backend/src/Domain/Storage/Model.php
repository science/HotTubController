<?php

declare(strict_types=1);

namespace HotTubController\Domain\Storage;

use DateTime;
use JsonSerializable;

abstract class Model implements JsonSerializable
{
    protected string $id;
    protected DateTime $createdAt;
    protected ?DateTime $updatedAt = null;

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? $this->generateId();
        $this->createdAt = new DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    protected function generateId(): string
    {
        return uniqid('', true);
    }

    public function markAsUpdated(): void
    {
        $this->updatedAt = new DateTime();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];

        return array_merge($data, $this->getModelData());
    }

    public function fromArray(array $data): static
    {
        $this->id = $data['id'] ?? $this->generateId();
        $this->createdAt = new DateTime($data['created_at'] ?? 'now');
        $this->updatedAt = isset($data['updated_at']) ? new DateTime($data['updated_at']) : null;

        $this->setModelData($data);
        return $this;
    }

    protected function touch(): void
    {
        $this->updatedAt = new DateTime();
    }

    abstract protected function getModelData(): array;

    abstract protected function setModelData(array $data): void;


    abstract public function validate(): array;

    abstract public static function getStorageKey(): string;
}