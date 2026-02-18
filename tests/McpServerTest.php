<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Graph\GraphTraverser;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\Mcp\ToolRegistry;
use SandraCore\Mcp\Tools\ListFactoriesTool;
use SandraCore\Mcp\Tools\DescribeFactoryTool;
use SandraCore\Mcp\Tools\SearchEntitiesTool;
use SandraCore\Mcp\Tools\GetEntityTool;
use SandraCore\Mcp\Tools\TraverseGraphTool;
use SandraCore\Mcp\Tools\CreateEntityTool;
use SandraCore\Mcp\Tools\LinkEntitiesTool;
use SandraCore\Mcp\Tools\UpdateEntityTool;
use SandraCore\Mcp\FactoryDiscovery;

/**
 * MCP Server + Tool tests.
 * Theme: librairie (livres, auteurs, genres)
 */
class McpServerTest extends SandraTestCase
{
    private McpServer $mcp;
    private EntityFactory $livres;

    protected function setUp(): void
    {
        parent::setUp();

        // Create some books
        $livresInit = new EntityFactory('livre', 'livresFile', $this->system);
        $livresInit->createNew([
            'titre' => 'Le Petit Prince',
            'auteur' => 'Saint-Exupery',
            'genre' => 'Conte',
            'annee' => '1943',
        ]);
        $livresInit->createNew([
            'titre' => 'Les Miserables',
            'auteur' => 'Victor Hugo',
            'genre' => 'Roman',
            'annee' => '1862',
        ]);
        $livresInit->createNew([
            'titre' => 'Germinal',
            'auteur' => 'Emile Zola',
            'genre' => 'Roman',
            'annee' => '1885',
        ]);

        // Repopulate
        $this->livres = new EntityFactory('livre', 'livresFile', $this->system);
        $this->livres->populateLocal();

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('livres', $this->livres, [
            'brothers' => [],
            'joined' => [],
        ]);
        $this->mcp->boot();
    }

    // --- Tool Registry ---

    public function testToolRegistryListsAndCalls(): void
    {
        $registry = new ToolRegistry();

        $mock = new class implements McpToolInterface {
            public function name(): string { return 'test_tool'; }
            public function description(): string { return 'A test tool'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $args): mixed { return ['echo' => $args['msg'] ?? 'none']; }
        };

        $registry->register($mock);

        $definitions = $registry->listDefinitions();
        $this->assertCount(1, $definitions);
        $this->assertEquals('test_tool', $definitions[0]['name']);

        $result = $registry->call('test_tool', ['msg' => 'hello']);
        $this->assertEquals(['echo' => 'hello'], $result);

        $this->assertTrue($registry->has('test_tool'));
        $this->assertFalse($registry->has('nope'));
    }

    public function testToolRegistryThrowsOnUnknown(): void
    {
        $registry = new ToolRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->call('nonexistent', []);
    }

    // --- ListFactories ---

    public function testListFactories(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_list_factories', []);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('livres', $result[0]['name']);
        $this->assertEquals('livre', $result[0]['entityIsa']);
        $this->assertEquals('livresFile', $result[0]['entityContainedIn']);
    }

    public function testListFactoriesMultiple(): void
    {
        $auteurs = new EntityFactory('auteur', 'auteursFile', $this->system);
        $auteurs->createNew(['nom' => 'Hugo']);
        $auteurs = new EntityFactory('auteur', 'auteursFile', $this->system);
        $auteurs->populateLocal();

        $this->mcp->register('auteurs', $auteurs);
        $this->mcp->boot();

        $result = $this->mcp->getToolRegistry()->call('sandra_list_factories', []);
        $this->assertCount(2, $result);
        $names = array_column($result, 'name');
        $this->assertContains('livres', $names);
        $this->assertContains('auteurs', $names);
    }

    // --- DescribeFactory ---

    public function testDescribeFactory(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_describe_factory', ['factory' => 'livres']);

        $this->assertEquals('livres', $result['name']);
        $this->assertEquals('livre', $result['entityIsa']);
        $this->assertEquals(3, $result['count']);
        $this->assertIsArray($result['referenceFields']);
        $this->assertContains('titre', $result['referenceFields']);
        $this->assertContains('auteur', $result['referenceFields']);
    }

    public function testDescribeUnknownFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown factory');
        $this->mcp->getToolRegistry()->call('sandra_describe_factory', ['factory' => 'inexistant']);
    }

    // --- SearchEntities ---

    public function testSearchEntities(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => 'Roman',
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    public function testSearchByField(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => 'Victor Hugo',
            'field' => 'auteur',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $foundAuteurs = array_column(array_column($result['items'], 'refs'), 'auteur');
        $this->assertContains('Victor Hugo', $foundAuteurs);
    }

    // --- GetEntity ---

    public function testGetEntity(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => $id,
        ]);

        $this->assertEquals($id, $result['id']);
        $this->assertArrayHasKey('refs', $result);
        $this->assertArrayHasKey('titre', $result['refs']);
    }

    public function testGetEntityNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => 999999,
        ]);
    }

    // --- TraverseGraph ---

    public function testTraverseBfs(): void
    {
        // Create a graph: A -> B -> C via 'suiteLogique'
        $graphFactory = new EntityFactory('noeud', 'noeudsFile', $this->system);
        $nodeA = $graphFactory->createNew(['label' => 'A']);
        $nodeB = $graphFactory->createNew(['label' => 'B']);
        $nodeC = $graphFactory->createNew(['label' => 'C']);

        // Create triplet links
        $nodeA->setBrotherEntity('suiteLogique', $nodeB, []);
        $nodeB->setBrotherEntity('suiteLogique', $nodeC, []);

        // Repopulate to get triplets
        $graphFactory = new EntityFactory('noeud', 'noeudsFile', $this->system);
        $graphFactory->populateLocal();
        $graphFactory->getTriplets();

        $this->mcp->register('noeuds', $graphFactory);
        $this->mcp->boot();

        $aId = (int)$nodeA->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_traverse', [
            'factory' => 'noeuds',
            'startId' => $aId,
            'verb' => 'suiteLogique',
            'algorithm' => 'bfs',
        ]);

        $this->assertArrayHasKey('entities', $result);
        $this->assertArrayHasKey('hasCycle', $result);
        $this->assertArrayHasKey('totalFound', $result);
        $this->assertGreaterThanOrEqual(1, $result['totalFound']);
    }

    public function testTraverseDfs(): void
    {
        $graphFactory = new EntityFactory('noeud_d', 'noeudsDFile', $this->system);
        $nodeA = $graphFactory->createNew(['label' => 'X']);
        $nodeB = $graphFactory->createNew(['label' => 'Y']);
        $nodeA->setBrotherEntity('lienDfs', $nodeB, []);

        $graphFactory = new EntityFactory('noeud_d', 'noeudsDFile', $this->system);
        $graphFactory->populateLocal();
        $graphFactory->getTriplets();

        $this->mcp->register('noeuds_d', $graphFactory);
        $this->mcp->boot();

        $aId = (int)$nodeA->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_traverse', [
            'factory' => 'noeuds_d',
            'startId' => $aId,
            'verb' => 'lienDfs',
            'algorithm' => 'dfs',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['totalFound']);
    }

    public function testTraverseAncestors(): void
    {
        $graphFactory = new EntityFactory('noeud_a', 'noeudsAFile', $this->system);
        $parent = $graphFactory->createNew(['label' => 'Parent']);
        $child = $graphFactory->createNew(['label' => 'Enfant']);
        $parent->setBrotherEntity('parentDe', $child, []);

        $graphFactory = new EntityFactory('noeud_a', 'noeudsAFile', $this->system);
        $graphFactory->populateLocal();
        $graphFactory->getTriplets();

        $this->mcp->register('noeuds_a', $graphFactory);
        $this->mcp->boot();

        $childId = (int)$child->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_traverse', [
            'factory' => 'noeuds_a',
            'startId' => $childId,
            'verb' => 'parentDe',
            'direction' => 'backward',
        ]);

        $this->assertArrayHasKey('entities', $result);
        $this->assertGreaterThanOrEqual(1, $result['totalFound']);
    }

    // --- CreateEntity ---

    public function testCreateEntity(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_entity', [
            'factory' => 'livres',
            'refs' => [
                'titre' => 'Candide',
                'auteur' => 'Voltaire',
                'genre' => 'Conte philosophique',
                'annee' => '1759',
            ],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertIsInt($result['id']);
        $this->assertEquals('Candide', $result['refs']['titre']);
        $this->assertEquals('Voltaire', $result['refs']['auteur']);
    }

    // --- LinkEntities ---

    public function testLinkEntities(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $sourceId = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_link_entities', [
            'factory' => 'livres',
            'sourceId' => $sourceId,
            'verb' => 'recommandePour',
            'target' => 'enfants',
        ]);

        $this->assertTrue($result['linked']);
        $this->assertEquals($sourceId, $result['source']);
        $this->assertEquals('recommandePour', $result['verb']);
        $this->assertEquals('enfants', $result['target']);
    }

    // --- UpdateEntity ---

    public function testUpdateEntity(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_update_entity', [
            'factory' => 'livres',
            'id' => $id,
            'refs' => ['annee' => '2000'],
        ]);

        $this->assertEquals($id, $result['id']);
        $this->assertEquals('2000', $result['refs']['annee']);
    }

    // --- EntitySerializer ---

    public function testEntitySerializer(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);

        $serialized = EntitySerializer::serialize($first);

        $this->assertArrayHasKey('id', $serialized);
        $this->assertIsInt($serialized['id']);
        $this->assertArrayHasKey('refs', $serialized);
        $this->assertIsArray($serialized['refs']);
        $this->assertArrayHasKey('titre', $serialized['refs']);
        // Should not contain brothers/joined by default
        $this->assertArrayNotHasKey('brothers', $serialized);
        $this->assertArrayNotHasKey('joined', $serialized);
    }

    // --- JSON-RPC Dispatch ---

    public function testJsonRpcDispatch(): void
    {
        // Test initialize
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
            ],
        ]);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertEquals('2025-11-25', $response['result']['protocolVersion']);
        $this->assertEquals('sandra-mcp', $response['result']['serverInfo']['name']);

        // Test notification (no response expected)
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $this->assertNull($response);

        // Test tools/list
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $this->assertEquals(2, $response['id']);
        $this->assertArrayHasKey('tools', $response['result']);
        $this->assertGreaterThanOrEqual(8, count($response['result']['tools']));

        // Verify all expected tools are listed
        $toolNames = array_column($response['result']['tools'], 'name');
        $this->assertContains('sandra_list_factories', $toolNames);
        $this->assertContains('sandra_describe_factory', $toolNames);
        $this->assertContains('sandra_search', $toolNames);
        $this->assertContains('sandra_get_entity', $toolNames);
        $this->assertContains('sandra_traverse', $toolNames);
        $this->assertContains('sandra_create_entity', $toolNames);
        $this->assertContains('sandra_link_entities', $toolNames);
        $this->assertContains('sandra_update_entity', $toolNames);

        // Test tools/call
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sandra_list_factories',
                'arguments' => [],
            ],
        ]);
        $this->assertEquals(3, $response['id']);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertEquals('text', $response['result']['content'][0]['type']);
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);

        // Test tools/call with error (unknown tool)
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'nonexistent_tool',
                'arguments' => [],
            ],
        ]);
        $this->assertEquals(4, $response['id']);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Error:', $response['result']['content'][0]['text']);

        // Test ping
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'ping',
        ]);
        $this->assertEquals(5, $response['id']);
        $this->assertArrayHasKey('result', $response);

        // Test unknown method
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'unknown/method',
        ]);
        $this->assertEquals(6, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32601, $response['error']['code']);
    }

    // --- Factory Discovery ---

    public function testFactoryDiscoveryFindsExistingTypes(): void
    {
        // The setUp already created 'livre' entities in the DB.
        // Also create a second type to verify multiple discovery.
        $auteursInit = new EntityFactory('auteur_disc', 'auteursDiscFile', $this->system);
        $auteursInit->createNew(['nom' => 'Hugo', 'pays' => 'France']);

        $discovery = new FactoryDiscovery($this->system);
        $factories = $discovery->discover();

        $this->assertIsArray($factories);
        // Should find at least 'livre' and 'auteur_disc'
        $this->assertArrayHasKey('livre', $factories);
        $this->assertArrayHasKey('auteur_disc', $factories);

        // Verify the discovered factories have the right types
        $this->assertEquals('livre', $factories['livre']->entityIsa);
        $this->assertEquals('livresFile', $factories['livre']->entityContainedIn);
        $this->assertEquals('auteur_disc', $factories['auteur_disc']->entityIsa);
        $this->assertEquals('auteursDiscFile', $factories['auteur_disc']->entityContainedIn);
    }

    public function testFactoryDiscoveryOnEmptyDb(): void
    {
        // Flush and create a clean system with no entities
        $flusher = new \SandraCore\System('phpUnitEmpty_', true);
        \SandraCore\Setup::flushDatagraph($flusher);
        $cleanSystem = new \SandraCore\System('phpUnitEmpty_', true);

        $discovery = new FactoryDiscovery($cleanSystem);
        $factories = $discovery->discover();

        $this->assertIsArray($factories);
        $this->assertEmpty($factories);
    }

    public function testMcpServerDiscoverRegistersFactories(): void
    {
        // Create a fresh MCP server and use discover()
        $mcp = new McpServer($this->system);
        $mcp->discover();
        $mcp->boot();

        // Should have discovered at least 'livre'
        $result = $mcp->getToolRegistry()->call('sandra_list_factories', []);
        $names = array_column($result, 'name');
        $this->assertContains('livre', $names);

        // Verify the discovered factory can describe itself
        $desc = $mcp->getToolRegistry()->call('sandra_describe_factory', ['factory' => 'livre']);
        $this->assertEquals('livre', $desc['entityIsa']);
        $this->assertEquals(3, $desc['count']);
    }
}
