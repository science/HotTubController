<?php

declare(strict_types=1);

namespace HotTubController\Domain\Storage;

class QueryBuilder
{
    private Repository $repository;
    private array $wheres = [];
    private ?array $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        // Handle two-parameter case: where('field', 'value')
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'in',
            'value' => $values,
        ];

        return $this;
    }

    public function whereNotNull(string $field): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'not_null',
            'value' => null,
        ];

        return $this;
    }

    public function whereNull(string $field): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'null',
            'value' => null,
        ];

        return $this;
    }

    public function whereBetween(string $field, array $range): self
    {
        if (count($range) !== 2) {
            throw new StorageException('whereBetween requires exactly 2 values in range array');
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => 'between',
            'value' => $range,
        ];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'])) {
            throw new StorageException('Order direction must be "asc" or "desc"');
        }

        $this->orderBy = ['field' => $field, 'direction' => $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $data = $this->repository->getData();
        
        // Apply where clauses
        $filtered = array_filter($data, [$this, 'matchesWhereConditions']);
        
        // Apply ordering
        if ($this->orderBy !== null) {
            usort($filtered, [$this, 'compareForSort']);
        }
        
        // Apply offset and limit
        if ($this->offset !== null) {
            $filtered = array_slice($filtered, $this->offset);
        }
        
        if ($this->limit !== null) {
            $filtered = array_slice($filtered, 0, $this->limit);
        }
        
        // Convert to models
        $models = [];
        foreach ($filtered as $item) {
            $models[] = $this->repository->createModel($item);
        }
        
        return $models;
    }

    public function first(): ?Model
    {
        $results = $this->limit(1)->get();
        return empty($results) ? null : $results[0];
    }

    public function count(): int
    {
        $data = $this->repository->getData();
        $filtered = array_filter($data, [$this, 'matchesWhereConditions']);
        return count($filtered);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    private function matchesWhereConditions(array $item): bool
    {
        foreach ($this->wheres as $where) {
            if (!$this->evaluateWhereCondition($item, $where)) {
                return false;
            }
        }
        
        return true;
    }

    private function evaluateWhereCondition(array $item, array $where): bool
    {
        $field = $where['field'];
        $operator = $where['operator'];
        $value = $where['value'];
        
        // Support dot notation for nested fields
        $itemValue = $this->getNestedValue($item, $field);
        
        return match ($operator) {
            '=' => $itemValue == $value,
            '!=' => $itemValue != $value,
            '>' => $itemValue > $value,
            '>=' => $itemValue >= $value,
            '<' => $itemValue < $value,
            '<=' => $itemValue <= $value,
            'like' => str_contains(strtolower((string)$itemValue), strtolower((string)$value)),
            'in' => in_array($itemValue, (array)$value),
            'not_in' => !in_array($itemValue, (array)$value),
            'null' => $itemValue === null,
            'not_null' => $itemValue !== null,
            'between' => $itemValue >= $value[0] && $itemValue <= $value[1],
            default => throw new StorageException("Unsupported operator: {$operator}"),
        };
    }

    private function getNestedValue(array $item, string $field): mixed
    {
        $keys = explode('.', $field);
        $value = $item;
        
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }

    private function compareForSort(array $a, array $b): int
    {
        $field = $this->orderBy['field'];
        $direction = $this->orderBy['direction'];
        
        $valueA = $this->getNestedValue($a, $field);
        $valueB = $this->getNestedValue($b, $field);
        
        // Handle null values
        if ($valueA === null && $valueB === null) {
            return 0;
        }
        if ($valueA === null) {
            return $direction === 'asc' ? -1 : 1;
        }
        if ($valueB === null) {
            return $direction === 'asc' ? 1 : -1;
        }
        
        // Compare values
        $result = $valueA <=> $valueB;
        
        return $direction === 'desc' ? -$result : $result;
    }
}