<?php

declare(strict_types=1);

namespace HotTubController\Domain\Token;

use DateTimeImmutable;
use DateTimeInterface;

class Token
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    public function __construct(
        private string $id,
        private string $token,
        private string $name,
        private DateTimeImmutable $created,
        private bool $active = true,
        private ?DateTimeImmutable $lastUsed = null,
        private string $role = self::ROLE_USER
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreated(): DateTimeImmutable
    {
        return $this->created;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getLastUsed(): ?DateTimeImmutable
    {
        return $this->lastUsed;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function deactivate(): self
    {
        return new self(
            $this->id,
            $this->token,
            $this->name,
            $this->created,
            false,
            $this->lastUsed,
            $this->role
        );
    }

    public function updateLastUsed(): self
    {
        return new self(
            $this->id,
            $this->token,
            $this->name,
            $this->created,
            $this->active,
            new DateTimeImmutable(),
            $this->role
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'name' => $this->name,
            'created' => $this->created->format(DateTimeInterface::ATOM),
            'active' => $this->active,
            'last_used' => $this->lastUsed?->format(DateTimeInterface::ATOM),
            'role' => $this->role,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['token'],
            $data['name'],
            new DateTimeImmutable($data['created']),
            $data['active'] ?? true,
            $data['last_used'] ? new DateTimeImmutable($data['last_used']) : null,
            $data['role'] ?? self::ROLE_USER
        );
    }

    public function getTokenPreview(): string
    {
        return substr($this->token, 0, 6) . '...';
    }

    public function equals(Token $other): bool
    {
        return $this->token === $other->token;
    }
}
