<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Query\QueryBuilder;
use SandraCore\Query\QueryResult;

final class QueryBuilderTest extends SandraTestCase
{
    private EntityFactory $alphabetFactory;
    private $a;
    private $b;
    private $c;
    private $d;
    private $e;
    private $f;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alphabetFactory = $this->createFactory('algebra', 'algebraFile');
        $this->a = $this->alphabetFactory->createNew(['name' => 'a', 'value' => '1']);
        $this->b = $this->alphabetFactory->createNew(['name' => 'b', 'value' => '2']);
        $this->c = $this->alphabetFactory->createNew(['name' => 'c', 'value' => '3']);
        $this->d = $this->alphabetFactory->createNew(['name' => 'd', 'value' => '4']);
        $this->e = $this->alphabetFactory->createNew(['name' => 'e', 'value' => '5']);
        $this->f = $this->alphabetFactory->createNew(['name' => 'f', 'value' => '6']);

        $this->a->setBrotherEntity('implies', $this->b, null);
        $this->a->setBrotherEntity('implies', $this->c, null);
        $this->e->setBrotherEntity('implies', $this->b, null);
        $this->d->setBrotherEntity('implies', $this->b, null);
        $this->f->setBrotherEntity('implies', $this->d, null);
        $this->c->setBrotherEntity('something', $this->b, null);
    }

    public function testQueryReturnsQueryBuilder(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $builder = $factory->query();
        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testGetReturnsQueryResult(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()->get();
        $this->assertInstanceOf(QueryResult::class, $result);
    }

    public function testGetAllEntities(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()->get();
        $this->assertCount(6, $result);
        $this->assertEquals(6, $result->getTotal());
    }

    public function testWhereHasBrother(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereHasBrother('implies', $this->b)
            ->get();

        // a, e, d all imply b
        $this->assertCount(3, $result);
    }

    public function testWhereNotHasBrother(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereNotHasBrother('implies', 0)
            ->get();

        // b, c have no implies (b and c don't imply anything... wait let me check)
        // a implies b,c; e implies b; d implies b; f implies d; c something b
        // Entities with 'implies' verb: a, e, d, f -> 4
        // Entities without: b, c -> 2
        $this->assertCount(2, $result);
    }

    public function testWhereRef(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('name', '=', 'a')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('a', $result->first()->get('name'));
    }

    public function testWhereShortcut(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->where('name', 'c')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('c', $result->first()->get('name'));
    }

    public function testWhereRefGreaterThan(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('value', '>', '3')
            ->get();

        // value > 3: d(4), e(5), f(6) = 3 entities
        $this->assertCount(3, $result);
    }

    public function testWhereRefLessThan(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('value', '<', '3')
            ->get();

        // value < 3: a(1), b(2) = 2 entities
        $this->assertCount(2, $result);
    }

    public function testWhereRefNotEqual(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('name', '!=', 'a')
            ->get();

        $this->assertCount(5, $result);
    }

    public function testCombinedBrotherAndRefFilter(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereHasBrother('implies', $this->b)
            ->whereRef('value', '>', '1')
            ->get();

        // implies b: a(1), e(5), d(4) -> after value > 1: e(5), d(4)
        $this->assertCount(2, $result);
    }

    public function testLimit(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->limit(3)
            ->get();

        $this->assertCount(3, $result);
    }

    public function testOffset(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertCount(2, $result);
    }

    public function testLimitWithRefFilter(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('value', '>', '2')
            ->limit(2)
            ->get();

        // value > 2: c(3), d(4), e(5), f(6) = 4 total, limit 2
        $this->assertCount(2, $result);
        $this->assertEquals(4, $result->getTotal());
    }

    public function testCount(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $count = $factory->query()->count();
        $this->assertEquals(6, $count);
    }

    public function testCountWithBrotherFilter(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $count = $factory->query()
            ->whereHasBrother('implies', $this->b)
            ->count();

        $this->assertEquals(3, $count);
    }

    public function testCountWithRefFilter(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $count = $factory->query()
            ->whereRef('value', '>=', '3')
            ->count();

        // c(3), d(4), e(5), f(6) = 4
        $this->assertEquals(4, $count);
    }

    public function testFirst(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $entity = $factory->query()
            ->where('name', 'b')
            ->first();

        $this->assertNotNull($entity);
        $this->assertEquals('b', $entity->get('name'));
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $entity = $factory->query()
            ->where('name', 'nonexistent')
            ->first();

        $this->assertNull($entity);
    }

    public function testQueryResultIterable(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()->limit(3)->get();

        $names = [];
        foreach ($result as $entity) {
            $names[] = $entity->get('name');
        }

        $this->assertCount(3, $names);
    }

    public function testQueryResultToArray(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()->get();
        $array = $result->toArray();

        $this->assertCount(6, $array);
        $this->assertIsArray($array);
    }

    public function testQueryResultLast(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()->get();

        $this->assertNotNull($result->last());
    }

    public function testQueryResultIsEmpty(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->where('name', 'nonexistent')
            ->get();

        $this->assertTrue($result->isEmpty());
        $this->assertNull($result->first());
        $this->assertNull($result->last());
    }

    public function testQueryResultMeta(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->limit(3)
            ->offset(1)
            ->get();

        $this->assertEquals(3, $result->getLimit());
        $this->assertEquals(1, $result->getOffset());
    }

    public function testMultipleRefFilters(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $result = $factory->query()
            ->whereRef('value', '>=', '2')
            ->whereRef('value', '<=', '4')
            ->get();

        // value >= 2 AND value <= 4: b(2), c(3), d(4) = 3
        $this->assertCount(3, $result);
    }

    public function testDoesNotMutateOriginalFactory(): void
    {
        $factory = $this->createFactory('algebra', 'algebraFile');
        $factory->populateLocal();
        $originalCount = count($factory->getEntities());

        // Run a query
        $factory->query()->whereHasBrother('implies', $this->b)->get();

        // Original factory should be unchanged
        $this->assertCount($originalCount, $factory->getEntities());
    }

    public function testOrderBy(): void
    {
        // Create a factory with numeric-sortable data
        $planetFactory = $this->createFactory('planet', 'solarSystemFile');
        $planetFactory->createNew(['name' => 'Jupiter', 'distance' => '778']);
        $planetFactory->createNew(['name' => 'Mars', 'distance' => '228']);
        $planetFactory->createNew(['name' => 'Earth', 'distance' => '150']);

        $result = $planetFactory->query()
            ->orderBy('name', 'ASC')
            ->get();

        $this->assertGreaterThan(0, $result->count());
    }
}
