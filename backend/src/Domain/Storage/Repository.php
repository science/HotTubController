<?php

declare(strict_types=1);

namespace HotTubController\Domain\Storage;

use HotTubController\Infrastructure\Storage\JsonStorageManager;

abstract class Repository implements RepositoryInterface
{
    protected JsonStorageManager $storageManager;
    protected string $modelClass;

    public function __construct(JsonStorageManager $storageManager)
    {
        $this->storageManager = $storageManager;
    }

    public function save(Model $model): bool
    {
        $errors = $model->validate();
        if (!empty($errors)) {
            throw new StorageException('Validation failed: ' . implode(', ', $errors));
        }

        $data = $this->loadData();
        $modelData = $model->toArray();
        
        // Update existing or add new
        $found = false;
        foreach ($data as $index => $item) {
            if ($item['id'] === $model->getId()) {
                $data[$index] = $modelData;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $data[] = $modelData;
        }

        return $this->saveData($data);
    }

    public function find(string $id): ?Model
    {
        $data = $this->loadData();
        
        foreach ($data as $item) {
            if ($item['id'] === $id) {
                return $this->createModel($item);
            }
        }
        
        return null;
    }

    public function findAll(): array
    {
        $data = $this->loadData();
        $models = [];
        
        foreach ($data as $item) {
            $models[] = $this->createModel($item);
        }
        
        return $models;
    }

    public function delete(string $id): bool
    {
        $data = $this->loadData();
        $originalCount = count($data);
        
        $data = array_filter($data, function ($item) use ($id) {
            return $item['id'] !== $id;
        });
        
        if (count($data) < $originalCount) {
            return $this->saveData(array_values($data));
        }
        
        return false;
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function count(): int
    {
        return count($this->loadData());
    }

    public function exists(string $id): bool
    {
        return $this->find($id) !== null;
    }

    public function getData(): array
    {
        return $this->loadData();
    }

    public function createModel(array $data): Model
    {
        $modelClass = $this->getModelClass();
        $model = new $modelClass();
        return $model->fromArray($data);
    }

    protected function loadData(): array
    {
        return $this->storageManager->load($this->getStorageKey());
    }

    protected function saveData(array $data): bool
    {
        return $this->storageManager->save($this->getStorageKey(), $data);
    }

    abstract protected function getStorageKey(): string;
    
    abstract protected function getModelClass(): string;
}