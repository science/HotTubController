<?php

declare(strict_types=1);

namespace HotTubController\Domain\Storage;

interface RepositoryInterface
{
    public function save(Model $model): bool;

    public function find(string $id): ?Model;

    public function findAll(): array;

    public function delete(string $id): bool;

    public function query(): QueryBuilder;

    public function count(): int;

    public function exists(string $id): bool;
}
