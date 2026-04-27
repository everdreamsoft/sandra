<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;

/**
 * Tests for EntityFactory::populateFromRefQuery — the structured
 * reference query that pushes filters + sort + limit down to SQL,
 * bypassing the factory's in-memory LIMIT/OFFSET window.
 *
 * Strategy: every test seeds MORE entities than the factory's default
 * limit, so any bug that naively falls back to populateLocal()'s window
 * would return the wrong top-N / miss matches outside the window.
 */
class EntityFactoryRefQueryTest extends SandraTestCase
{
    private const ISA = 'sogPlayer';
    private const FILE = 'sogPlayerFile';

    /**
     * Window size intentionally smaller than the number of seeded entities.
     * A correct implementation must ignore this for populateFromRefQuery.
     */
    private const WINDOW = 5;

    /** @var int[] lastLogin values seeded, varying digit-length to expose lexi-vs-numeric sort bugs */
    private array $seededLogins = [];

    private function seed(int $count = 25): EntityFactory
    {
        $factory = $this->createFactory(self::ISA, self::FILE);
        $factory->populateLocal();

        // Varied digit-lengths: lexicographic sort gives "9" > "1770000000".
        // Numeric sort gives 1770000000 > 9. Tests must distinguish.
        $loginBases = [5, 9, 50, 100, 1000, 1770000000, 1770000001, 1770000002,
                       1770000050, 1770000100, 1770001000, 1780000000];

        for ($i = 0; $i < $count; $i++) {
            $login = $loginBases[$i % count($loginBases)] + $i;
            $level = ($i % 4 === 0) ? 80 : (($i % 3 === 0) ? 50 : 20);
            $status = ($i % 5 === 0) ? 'inactive' : 'active';
            $guild = ['A', 'B', 'C', 'D'][$i % 4];

            $factory->createNew([
                'name' => "player_{$i}",
                'lastLogin' => (string)$login,
                'level' => (string)$level,
                'status' => $status,
                'guild' => $guild,
            ]);

            $this->seededLogins[] = $login;
        }

        // Fresh factory with a tiny window, to prove ref-query bypasses it.
        $freshFactory = $this->createFactory(self::ISA, self::FILE);
        $freshFactory->setDefaultLimit(self::WINDOW);
        return $freshFactory;
    }

    public function testPopulateLocalIsWindowedAndRefQueryIsNot(): void
    {
        $factory = $this->seed(25);

        // Sanity check: naive populateLocal() only sees the window.
        $factory->populateLocal();
        $this->assertCount(self::WINDOW, $factory->getEntities(),
            'populateLocal with small limit should return exactly the window');

        // Fresh factory so populateLocal residue does not contaminate the query.
        $qFactory = $this->createFactory(self::ISA, self::FILE);
        $qFactory->setDefaultLimit(self::WINDOW);

        $result = $qFactory->populateFromRefQuery(
            filters: [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            sort: null,
            limit: 100
        );

        // There are 25 entities total, 5 are inactive (indices 0,5,10,15,20),
        // so 20 should match. This is 4× the factory window — proves pushdown.
        $this->assertCount(20, $result,
            'populateFromRefQuery must return full matched set, ignoring factory window');
    }

    public function testNumericSortBeatsLexicographic(): void
    {
        $factory = $this->seed(25);

        $numericSorted = $factory->populateFromRefQuery(
            filters: [['ref' => 'lastLogin', 'op' => '>', 'value' => 0]],
            sort: ['ref' => 'lastLogin', 'direction' => 'DESC', 'numeric' => true],
            limit: 5
        );

        $numericLogins = array_map(
            fn($e) => (int)$e->get('lastLogin'),
            array_values($numericSorted)
        );

        // Must be sorted descending AS NUMBERS
        $sorted = $numericLogins;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $numericLogins,
            'Numeric sort must return values in true numeric descending order');

        // And the top value must be the actual max of the seeded set,
        // not the lexicographic max (which would favor "9..." over "1770000...").
        $this->assertSame(max($this->seededLogins), $numericLogins[0],
            'Top of numeric DESC must be the real numeric maximum');

        // Confirm lexicographic would have given a different top — otherwise
        // the test is vacuous. Lexicographic max of mixed-length strings with
        // leading digit "9" always beats "1770000000".
        $asStrings = array_map(fn($v) => (string)$v, $this->seededLogins);
        rsort($asStrings, SORT_STRING);
        $this->assertNotSame((int)$asStrings[0], $numericLogins[0],
            'Seed data must expose the lexi-vs-numeric divergence for this test to be meaningful');
    }

    public function testMultiFilterAndSemantics(): void
    {
        $factory = $this->seed(25);

        $result = $factory->populateFromRefQuery(
            filters: [
                ['ref' => 'status', 'op' => '=', 'value' => 'active'],
                ['ref' => 'level',  'op' => '>=', 'value' => 50],
            ],
            limit: 100
        );

        foreach ($result as $entity) {
            $this->assertSame('active', $entity->get('status'));
            $this->assertGreaterThanOrEqual(50, (int)$entity->get('level'));
        }
        $this->assertNotEmpty($result, 'Expected at least some active+high-level players');
    }

    public function testInOperator(): void
    {
        $factory = $this->seed(25);

        $result = $factory->populateFromRefQuery(
            filters: [['ref' => 'guild', 'op' => 'IN', 'value' => ['A', 'C']]],
            limit: 100
        );

        foreach ($result as $entity) {
            $this->assertContains($entity->get('guild'), ['A', 'C']);
        }
        // 25 entities, 4 guilds round-robin → roughly half (13: indices 0,2,4,...) in {A,C}
        $this->assertCount(13, $result);
    }

    public function testSortOnRefNotInFilters(): void
    {
        $factory = $this->seed(25);

        // Filter on status, sort on lastLogin — lastLogin is not in filters,
        // exercises the LEFT JOIN sort-alias path.
        $result = $factory->populateFromRefQuery(
            filters: [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            sort: ['ref' => 'lastLogin', 'direction' => 'ASC', 'numeric' => true],
            limit: 10
        );

        $logins = array_map(
            fn($e) => (int)$e->get('lastLogin'),
            array_values($result)
        );
        $expected = $logins;
        sort($expected, SORT_NUMERIC);
        $this->assertSame($expected, $logins,
            'Sort on a ref outside the filter set must still produce ordered output');
    }

    public function testLimitOffsetPagination(): void
    {
        $factory = $this->seed(25);

        $page1 = $factory->populateFromRefQuery(
            filters: [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            sort: ['ref' => 'lastLogin', 'direction' => 'ASC', 'numeric' => true],
            limit: 5,
            offset: 0
        );
        $this->assertCount(5, $page1);

        // Fresh factory for the next page — otherwise cached entity state interferes.
        $factory2 = $this->createFactory(self::ISA, self::FILE);
        $factory2->setDefaultLimit(self::WINDOW);
        $page2 = $factory2->populateFromRefQuery(
            filters: [['ref' => 'status', 'op' => '=', 'value' => 'active']],
            sort: ['ref' => 'lastLogin', 'direction' => 'ASC', 'numeric' => true],
            limit: 5,
            offset: 5
        );
        $this->assertCount(5, $page2);

        // $entityArray is keyed by idConceptStart — use array keys as IDs.
        $ids1 = array_keys($page1);
        $ids2 = array_keys($page2);
        $this->assertEmpty(array_intersect($ids1, $ids2),
            'page1 and page2 must not overlap');
    }

    public function testEmptyFiltersReturnsEmpty(): void
    {
        $factory = $this->seed(5);
        $result = $factory->populateFromRefQuery(filters: []);
        $this->assertSame([], $result);
    }

    public function testNoMatchReturnsEmpty(): void
    {
        $factory = $this->seed(25);
        $result = $factory->populateFromRefQuery(
            filters: [['ref' => 'status', 'op' => '=', 'value' => '__nonexistent__']]
        );
        $this->assertSame([], $result);
    }
}
