<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Entity;
use SandraCore\Graph\GraphTraverser;
use SandraCore\Graph\Path;
use SandraCore\Graph\TraversalResult;

final class GraphTraversalTest extends SandraTestCase
{
    private EntityFactory $factory;
    private GraphTraverser $traverser;

    /** @var Entity[] keyed by name */
    private array $nodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create entities first
        $this->factory = $this->createFactory('node', 'nodeFile');

        $a = $this->factory->createNew(['name' => 'a']);
        $b = $this->factory->createNew(['name' => 'b']);
        $c = $this->factory->createNew(['name' => 'c']);
        $d = $this->factory->createNew(['name' => 'd']);
        $e = $this->factory->createNew(['name' => 'e']);
        $f = $this->factory->createNew(['name' => 'f']);

        // Build graph: a->b, a->e, b->c, c->d, e->d, d->f
        $a->setBrotherEntity('linksTo', $b, null);
        $a->setBrotherEntity('linksTo', $e, null);
        $b->setBrotherEntity('linksTo', $c, null);
        $c->setBrotherEntity('linksTo', $d, null);
        $e->setBrotherEntity('linksTo', $d, null);
        $d->setBrotherEntity('linksTo', $f, null);

        // Re-populate to have clean entityArray keyed by conceptId
        $this->factory = $this->createFactory('node', 'nodeFile');
        $this->factory->populateLocal();
        $this->factory->getTriplets();

        // Map nodes by name
        foreach ($this->factory->getEntities() as $entity) {
            $name = $entity->get('name');
            if ($name !== null) {
                $this->nodes[$name] = $entity;
            }
        }

        $this->traverser = new GraphTraverser($this->system);
    }

    // --- BFS tests ---

    public function testBfsDirectNeighbors(): void
    {
        $result = $this->traverser->bfs($this->nodes['a'], 'linksTo', 1);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        $this->assertEquals(['b', 'e'], $names);
    }

    public function testBfsDeepNeighbors(): void
    {
        $result = $this->traverser->bfs($this->nodes['a'], 'linksTo', 10);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        $this->assertEquals(['b', 'c', 'd', 'e', 'f'], $names);
    }

    public function testBfsRespectsMaxDepth(): void
    {
        $result = $this->traverser->bfs($this->nodes['a'], 'linksTo', 2);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        // depth 1: b, e; depth 2: c, d
        $this->assertEquals(['b', 'c', 'd', 'e'], $names);
    }

    public function testBfsGroupsByDepth(): void
    {
        $result = $this->traverser->bfs($this->nodes['a'], 'linksTo', 10);
        $depth1 = $this->entityNames($result->getAtDepth(1));
        sort($depth1);
        $this->assertEquals(['b', 'e'], $depth1);

        $depth2 = $this->entityNames($result->getAtDepth(2));
        sort($depth2);
        $this->assertEquals(['c', 'd'], $depth2);
    }

    // --- DFS tests ---

    public function testDfsFindsAllReachable(): void
    {
        $result = $this->traverser->dfs($this->nodes['a'], 'linksTo', 10);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        $this->assertEquals(['b', 'c', 'd', 'e', 'f'], $names);
    }

    // --- descendants ---

    public function testDescendantsAliasForBfs(): void
    {
        $result = $this->traverser->descendants($this->nodes['a'], 'linksTo', 1);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        $this->assertEquals(['b', 'e'], $names);
    }

    // --- ancestors ---

    public function testAncestorsFindsParents(): void
    {
        $result = $this->traverser->ancestors($this->nodes['d'], 'linksTo', 10);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        // d's parents: c and e; c's parent: b; e's parent: a; b's parent: a
        $this->assertEquals(['a', 'b', 'c', 'e'], $names);
    }

    public function testAncestorsRespectsMaxDepth(): void
    {
        $result = $this->traverser->ancestors($this->nodes['d'], 'linksTo', 1);
        $names = $this->entityNames($result->getEntities());
        sort($names);
        // Only direct parents of d: c and e
        $this->assertEquals(['c', 'e'], $names);
    }

    // --- hasCycle ---

    public function testHasCycleFalseOnDag(): void
    {
        $this->assertFalse($this->traverser->hasCycle($this->nodes['a'], 'linksTo'));
    }

    public function testHasCycleTrueAfterAddingCycleEdge(): void
    {
        // Add cycle: c -> b (via setBrotherEntity using b's concept ID as target)
        $bConceptId = $this->nodes['b']->subjectConcept->idConcept;
        $this->nodes['c']->setBrotherEntity('linksTo', $bConceptId, null);

        // Refresh triplets
        $this->factory->tripletRetrieved = false;
        $this->factory->getTriplets();

        $this->assertTrue($this->traverser->hasCycle($this->nodes['a'], 'linksTo'));
    }

    // --- findPaths ---

    public function testFindPathsSinglePath(): void
    {
        $paths = $this->traverser->findPaths($this->nodes['b'], $this->nodes['f'], ['linksTo']);
        $this->assertCount(1, $paths);
        $this->assertEquals(3, $paths[0]->getLength()); // b->c->d->f
    }

    public function testFindPathsMultiplePaths(): void
    {
        $paths = $this->traverser->findPaths($this->nodes['a'], $this->nodes['d'], ['linksTo']);
        $this->assertCount(2, $paths);
        // a->b->c->d (length 3) and a->e->d (length 2)
        $lengths = array_map(fn(Path $p) => $p->getLength(), $paths);
        sort($lengths);
        $this->assertEquals([2, 3], $lengths);
    }

    public function testFindPathsNoPaths(): void
    {
        $paths = $this->traverser->findPaths($this->nodes['f'], $this->nodes['a'], ['linksTo']);
        $this->assertCount(0, $paths);
    }

    // --- shortestPath ---

    public function testShortestPathPicksShortest(): void
    {
        $path = $this->traverser->shortestPath($this->nodes['a'], $this->nodes['d'], ['linksTo']);
        $this->assertNotNull($path);
        $this->assertEquals(2, $path->getLength()); // a->e->d is shorter than a->b->c->d
    }

    public function testShortestPathReturnsNullWhenNone(): void
    {
        $path = $this->traverser->shortestPath($this->nodes['f'], $this->nodes['a'], ['linksTo']);
        $this->assertNull($path);
    }

    // --- Path unit tests ---

    public function testPathContains(): void
    {
        $path = new Path([$this->nodes['a'], $this->nodes['b']]);
        $this->assertTrue($path->contains($this->nodes['a']));
        $this->assertTrue($path->contains($this->nodes['b']));
        $this->assertFalse($path->contains($this->nodes['c']));
    }

    public function testPathLength(): void
    {
        $path = new Path([$this->nodes['a'], $this->nodes['b'], $this->nodes['c']]);
        $this->assertEquals(2, $path->getLength());
    }

    public function testPathStartEnd(): void
    {
        $path = new Path([$this->nodes['a'], $this->nodes['b'], $this->nodes['c']]);
        $this->assertSame($this->nodes['a'], $path->getStart());
        $this->assertSame($this->nodes['c'], $path->getEnd());
    }

    // --- TraversalResult unit tests ---

    public function testTraversalResultEmpty(): void
    {
        $result = new TraversalResult();
        $this->assertTrue($result->isEmpty());
        $this->assertEquals(0, $result->count());
        $this->assertEquals(0, $result->getMaxDepth());
    }

    public function testTraversalResultGetMaxDepth(): void
    {
        $result = $this->traverser->bfs($this->nodes['a'], 'linksTo', 10);
        $this->assertGreaterThan(0, $result->getMaxDepth());
    }

    /**
     * Helper to extract entity names from an array
     * @param Entity[] $entities
     * @return string[]
     */
    private function entityNames(array $entities): array
    {
        return array_map(fn(Entity $e) => $e->get('name'), $entities);
    }
}
