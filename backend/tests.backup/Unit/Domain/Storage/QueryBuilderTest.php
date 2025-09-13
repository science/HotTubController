<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Storage;

use PHPUnit\Framework\TestCase;
use HotTubController\Domain\Storage\QueryBuilder;
use HotTubController\Domain\Storage\Repository;
use HotTubController\Domain\Storage\Model;
use HotTubController\Domain\Storage\StorageException;
use HotTubController\Domain\Storage\RepositoryInterface;

class QueryBuilderTest extends TestCase
{
    private QueryBuilder $queryBuilder;
    private MockRepository $mockRepository;

    protected function setUp(): void
    {
        $this->mockRepository = new MockRepository();
        $this->queryBuilder = new QueryBuilder($this->mockRepository);
    }

    public function testBasicWhereClause(): void
    {
        $results = $this->queryBuilder
            ->where('status', 'active')
            ->get();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertEquals('active', $result['status']);
        }
    }

    public function testWhereWithOperator(): void
    {
        $results = $this->queryBuilder
            ->where('age', '>', 25)
            ->get();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertGreaterThan(25, $result['age']);
        }
    }

    public function testWhereIn(): void
    {
        $results = $this->queryBuilder
            ->whereIn('status', ['active', 'pending'])
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereNotNull(): void
    {
        $results = $this->queryBuilder
            ->whereNotNull('email')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereNull(): void
    {
        $results = $this->queryBuilder
            ->whereNull('email')
            ->get();

        $this->assertCount(1, $results);
    }

    public function testWhereBetween(): void
    {
        $results = $this->queryBuilder
            ->whereBetween('age', [25, 30])
            ->get();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(25, $result['age']);
            $this->assertLessThanOrEqual(30, $result['age']);
        }
    }

    public function testWhereBetweenInvalidRange(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('whereBetween requires exactly 2 values in range array');

        $this->queryBuilder->whereBetween('age', [20]);
    }

    public function testMultipleWhereConditions(): void
    {
        $results = $this->queryBuilder
            ->where('status', 'active')
            ->where('age', '>', 20)
            ->get();

        $this->assertCount(2, $results);
    }

    public function testOrderByAscending(): void
    {
        $results = $this->queryBuilder
            ->orderBy('age', 'asc')
            ->get();

        $ages = array_column($results, 'age');
        $sortedAges = $ages;
        sort($sortedAges);

        $this->assertEquals($sortedAges, $ages);
    }

    public function testOrderByDescending(): void
    {
        $results = $this->queryBuilder
            ->orderBy('age', 'desc')
            ->get();

        $ages = array_column($results, 'age');
        $sortedAges = $ages;
        rsort($sortedAges);

        $this->assertEquals($sortedAges, $ages);
    }

    public function testInvalidOrderDirection(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Order direction must be "asc" or "desc"');

        $this->queryBuilder->orderBy('age', 'invalid');
    }

    public function testLimit(): void
    {
        $results = $this->queryBuilder
            ->orderBy('age', 'asc')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
    }

    public function testOffset(): void
    {
        $allResults = $this->queryBuilder
            ->orderBy('age', 'asc')
            ->get();

        $offsetResults = $this->queryBuilder
            ->orderBy('age', 'asc')
            ->offset(1)
            ->get();

        $this->assertCount(count($allResults) - 1, $offsetResults);
        $this->assertEquals($allResults[1]['name'], $offsetResults[0]['name']);
    }

    public function testLimitWithOffset(): void
    {
        $results = $this->queryBuilder
            ->orderBy('age', 'asc')
            ->offset(1)
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
    }

    public function testFirst(): void
    {
        $result = $this->queryBuilder
            ->where('status', 'active')
            ->orderBy('age', 'asc')
            ->first();

        $this->assertNotNull($result);
        $this->assertEquals('active', $result['status']);
    }

    public function testFirstReturnsNull(): void
    {
        $result = $this->queryBuilder
            ->where('status', 'nonexistent')
            ->first();

        $this->assertNull($result);
    }

    public function testCount(): void
    {
        $count = $this->queryBuilder
            ->where('status', 'active')
            ->count();

        $this->assertEquals(2, $count);
    }

    public function testExists(): void
    {
        $this->assertTrue($this->queryBuilder->where('status', 'active')->exists());
        $this->assertFalse($this->queryBuilder->where('status', 'nonexistent')->exists());
    }

    public function testNestedFieldAccess(): void
    {
        $results = $this->queryBuilder
            ->where('profile.location', 'NYC')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('NYC', $results[0]['profile']['location']);
    }

    public function testLikeOperator(): void
    {
        $results = $this->queryBuilder
            ->where('name', 'like', 'jo') // Use lowercase to match 'john@' and 'johnson'
            ->get();

        $this->assertCount(2, $results); // Should match 'John Doe' and 'Bob Johnson'
        foreach ($results as $result) {
            $this->assertStringContainsStringIgnoringCase('jo', $result['name']);
        }
    }

    public function testUnsupportedOperator(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unsupported operator: unsupported');

        $this->queryBuilder
            ->where('field', 'unsupported', 'value')
            ->get();
    }

    public function testOrderByWithNullValues(): void
    {
        // Add item with null email to test data
        $this->mockRepository->addTestData([
            'id' => '5',
            'name' => 'Null Email',
            'age' => 35,
            'status' => 'active',
            'email' => null,
            'profile' => []
        ]);

        $results = $this->queryBuilder
            ->orderBy('email', 'asc')
            ->get();

        // Null values should come first in ascending order
        $this->assertNull($results[0]['email']);
    }

    public function testComplexQuery(): void
    {
        $results = $this->queryBuilder
            ->where('age', '>', 20)
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('email')
            ->orderBy('age', 'desc')
            ->limit(2)
            ->get();

        $this->assertLessThanOrEqual(2, count($results));

        foreach ($results as $result) {
            $this->assertGreaterThan(20, $result['age']);
            $this->assertContains($result['status'], ['active', 'pending']);
            $this->assertNotNull($result['email']);
        }
    }
}

class MockRepository extends Repository
{
    private array $testData = [
        [
            'id' => '1',
            'name' => 'John Doe',
            'age' => 25,
            'status' => 'active',
            'email' => 'john@example.com',
            'profile' => ['location' => 'NYC']
        ],
        [
            'id' => '2',
            'name' => 'Jane Smith',
            'age' => 30,
            'status' => 'active',
            'email' => 'jane@example.com',
            'profile' => ['location' => 'LA']
        ],
        [
            'id' => '3',
            'name' => 'Bob Johnson',
            'age' => 35,
            'status' => 'inactive',
            'email' => 'bob@example.com',
            'profile' => ['location' => 'Chicago']
        ],
        [
            'id' => '4',
            'name' => 'Alice Brown',
            'age' => 20,
            'status' => 'pending',
            'email' => null,
            'profile' => []
        ]
    ];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function getData(): array
    {
        return $this->testData;
    }

    public function addTestData(array $item): void
    {
        $this->testData[] = $item;
    }

    public function createModel(array $data): Model
    {
        return new MockModel($data); // Return mock model for testing
    }

    protected function getStorageKey(): string
    {
        return 'mock';
    }

    protected function getModelClass(): string
    {
        return MockModel::class;
    }
}

class MockModel extends Model implements \ArrayAccess
{
    private array $data = [];

    public function __construct(array $data = [])
    {
        parent::__construct($data['id'] ?? null);
        $this->data = $data;
    }

    protected function getModelData(): array
    {
        return $this->data;
    }

    protected function setModelData(array $data): void
    {
        $this->data = $data;
    }

    protected function getRepository(): RepositoryInterface
    {
        return new MockRepository();
    }

    public function validate(): array
    {
        return [];
    }

    public static function getStorageKey(): string
    {
        return 'mock_models';
    }

    // Allow array access for backward compatibility with tests
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }
}
