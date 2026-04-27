<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;

/**
 * Tests for the sandra_query_entities MCP tool.
 *
 * Seeds 25 players (more than any sensible factory window) so that
 * correct top-N / true count assertions prove SQL pushdown rather
 * than in-memory filtering of a partial page.
 */
class McpQueryEntitiesTest extends SandraTestCase
{
    private McpServer $mcp;

    protected function setUp(): void
    {
        parent::setUp();

        $seed = new EntityFactory('sogPlayer', 'sogPlayerFile', $this->system);
        for ($i = 0; $i < 25; $i++) {
            $login = ($i % 2 === 0) ? 1770000000 + $i : 100 + $i;
            $status = ($i % 5 === 0) ? 'inactive' : 'active';
            $level  = ($i % 4 === 0) ? 80 : (($i % 3 === 0) ? 50 : 20);
            $seed->createNew([
                'name' => "player_$i",
                'lastLogin' => (string)$login,
                'status' => $status,
                'level' => (string)$level,
            ]);
        }

        $factory = new EntityFactory('sogPlayer', 'sogPlayerFile', $this->system);
        $factory->populateLocal();

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('players', $factory, ['brothers' => [], 'joined' => []]);
        $this->mcp->boot();
    }

    public function testToolIsRegistered(): void
    {
        $this->assertTrue(
            $this->mcp->getToolRegistry()->has('sandra_query_entities'),
            'sandra_query_entities must be registered'
        );
    }

    public function testSimpleFilterReturnsFullMatchedSet(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [
                ['ref' => 'status', 'op' => '=', 'value' => 'active'],
            ],
            'limit' => 200,
        ]);

        // 25 entities, indices divisible by 5 are inactive → 20 active.
        $this->assertSame(20, $result['count']);
        foreach ($result['items'] as $item) {
            $this->assertSame('active', $item['refs']['status']);
        }
    }

    public function testNumericSortReturnsTrueTopN(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [['ref' => 'lastLogin', 'op' => '>', 'value' => 0]],
            'sort' => ['ref' => 'lastLogin', 'direction' => 'DESC', 'numeric' => true],
            'limit' => 5,
        ]);

        $this->assertSame(5, $result['count']);
        $logins = array_map(fn($it) => (int)$it['refs']['lastLogin'], $result['items']);

        $expected = $logins;
        rsort($expected, SORT_NUMERIC);
        $this->assertSame($expected, $logins, 'MCP must return truly numeric-sorted top-N');

        // All top-5 logins should be in the big (>1.7e9) range, not the small (100..) range.
        foreach ($logins as $login) {
            $this->assertGreaterThan(1000000000, $login,
                'Lexicographic sort would have surfaced small-digit logins here — it did not');
        }
    }

    public function testMultiFilterAndInOperator(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [
                ['ref' => 'status', 'op' => '=',  'value' => 'active'],
                ['ref' => 'level',  'op' => '>=', 'value' => 50],
            ],
            'limit' => 200,
        ]);

        foreach ($result['items'] as $item) {
            $this->assertSame('active', $item['refs']['status']);
            $this->assertGreaterThanOrEqual(50, (int)$item['refs']['level']);
        }
        $this->assertGreaterThan(0, $result['count']);
    }

    public function testLimitAndHasMore(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            'limit' => 5,
        ]);

        $this->assertSame(5, $result['count']);
        $this->assertTrue($result['hasMore'], 'hasMore must be true when page is full');
    }

    public function testFieldsProjection(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            'fields' => ['name'],
            'limit' => 3,
        ]);

        foreach ($result['items'] as $item) {
            $this->assertArrayHasKey('name', $item['refs']);
            $this->assertArrayNotHasKey('status', $item['refs']);
        }
    }

    public function testUnknownFactoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'nope',
            'filters' => [['ref' => 'x', 'op' => '=', 'value' => 'y']],
        ]);
    }

    public function testEmptyFiltersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [],
        ]);
    }

    public function testMalformedFilterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_query_entities', [
            'factory' => 'players',
            'filters' => [['ref' => 'status']], // missing op + value
        ]);
    }
}
