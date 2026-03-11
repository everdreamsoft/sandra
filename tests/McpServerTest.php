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
use SandraCore\Mcp\Tools\GetTripletsTool;
use SandraCore\Mcp\Tools\GetReferencesTool;
use SandraCore\Mcp\Tools\ListEntitiesTool;
use SandraCore\Mcp\Tools\GetSchemaTool;
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
        // referenceFields now returns objects with name and example
        $fieldNames = array_column($result['referenceFields'], 'name');
        $this->assertContains('titre', $fieldNames);
        $this->assertContains('auteur', $fieldNames);
        // Should have example values from data
        $titreField = null;
        foreach ($result['referenceFields'] as $f) {
            if ($f['name'] === 'titre') { $titreField = $f; break; }
        }
        $this->assertNotNull($titreField);
        $this->assertArrayHasKey('example', $titreField);
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
        $this->assertGreaterThanOrEqual(16, count($response['result']['tools']));

        // Verify all expected tools are listed
        $toolNames = array_column($response['result']['tools'], 'name');
        $this->assertContains('sandra_get_schema', $toolNames);
        $this->assertContains('sandra_list_factories', $toolNames);
        $this->assertContains('sandra_describe_factory', $toolNames);
        $this->assertContains('sandra_list_entities', $toolNames);
        $this->assertContains('sandra_search', $toolNames);
        $this->assertContains('sandra_get_entity', $toolNames);
        $this->assertContains('sandra_traverse', $toolNames);
        $this->assertContains('sandra_create_entity', $toolNames);
        $this->assertContains('sandra_link_entities', $toolNames);
        $this->assertContains('sandra_update_entity', $toolNames);
        $this->assertContains('sandra_get_triplets', $toolNames);
        $this->assertContains('sandra_get_references', $toolNames);
        $this->assertContains('sandra_create_concept', $toolNames);
        $this->assertContains('sandra_create_triplet', $toolNames);
        $this->assertContains('sandra_create_factory', $toolNames);
        $this->assertContains('sandra_delete_triplet', $toolNames);

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

    // --- GetTriplets ---

    public function testGetTripletsOutgoing(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $id,
            'direction' => 'outgoing',
        ]);

        $this->assertArrayHasKey('conceptId', $result);
        $this->assertEquals($id, $result['conceptId']);
        $this->assertArrayHasKey('triplets', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsArray($result['triplets']);
        // Should have at least is_a and contained_in_file
        $this->assertGreaterThanOrEqual(2, $result['total']);

        // Verify triplet structure
        $triplet = $result['triplets'][0];
        $this->assertArrayHasKey('direction', $triplet);
        $this->assertEquals('outgoing', $triplet['direction']);
        $this->assertArrayHasKey('linkId', $triplet);
        $this->assertArrayHasKey('subject', $triplet);
        $this->assertArrayHasKey('verb', $triplet);
        $this->assertArrayHasKey('target', $triplet);
        $this->assertArrayHasKey('subjectId', $triplet);
        $this->assertArrayHasKey('verbId', $triplet);
        $this->assertArrayHasKey('targetId', $triplet);
    }

    public function testGetTripletsIncoming(): void
    {
        // Link an entity so we have an incoming triplet
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $second = next($entities);
        $first->setBrotherEntity('recommande', $second, []);

        $targetId = (int)$second->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $targetId,
            'direction' => 'incoming',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $incoming = array_filter($result['triplets'], fn($t) => $t['direction'] === 'incoming');
        $this->assertNotEmpty($incoming);
    }

    public function testGetTripletsBoth(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $id,
        ]);

        // Default direction is 'both'
        $this->assertArrayHasKey('triplets', $result);
        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    public function testGetTripletsNonExistentConcept(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => 999999,
        ]);

        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['triplets']);
    }

    // --- GetReferences ---

    public function testGetReferencesForEntity(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_references', [
            'conceptId' => $id,
        ]);

        $this->assertArrayHasKey('conceptId', $result);
        $this->assertEquals($id, $result['conceptId']);
        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('totalLinks', $result);
        $this->assertIsArray($result['links']);
        // Entity should have at least one link with references (contained_in_file link has refs)
        $this->assertGreaterThanOrEqual(1, $result['totalLinks']);

        // Find a link that has reference data
        $allRefs = [];
        foreach ($result['links'] as $link) {
            $this->assertArrayHasKey('linkId', $link);
            $this->assertArrayHasKey('verb', $link);
            $this->assertArrayHasKey('target', $link);
            $this->assertArrayHasKey('references', $link);
            $this->assertIsArray($link['references']);
            $allRefs = array_merge($allRefs, $link['references']);
        }
        // Should contain the entity's ref data (titre, auteur, etc.)
        $this->assertArrayHasKey('titre', $allRefs);
    }

    public function testGetReferencesForSpecificLink(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        // First get the triplets to find a link ID
        $triplets = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $id,
            'direction' => 'outgoing',
        ]);

        // Find the contained_in_file link (it has the entity references)
        $cifLink = null;
        foreach ($triplets['triplets'] as $t) {
            if ($t['verb'] === 'contained_in_file') {
                $cifLink = $t['linkId'];
                break;
            }
        }
        $this->assertNotNull($cifLink, 'Should find a contained_in_file link');

        $result = $this->mcp->getToolRegistry()->call('sandra_get_references', [
            'conceptId' => $id,
            'linkId' => $cifLink,
        ]);

        $this->assertEquals(1, $result['totalLinks']);
        $this->assertEquals($cifLink, $result['links'][0]['linkId']);
        $this->assertArrayHasKey('titre', $result['links'][0]['references']);
    }

    public function testGetReferencesNonExistentConcept(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_references', [
            'conceptId' => 999999,
        ]);

        $this->assertEquals(0, $result['totalLinks']);
        $this->assertEmpty($result['links']);
    }

    // --- Fields filter ---

    public function testSearchWithFieldsFilter(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => 'Roman',
            'fields' => ['titre'],
        ]);

        $this->assertGreaterThanOrEqual(2, $result['total']);
        foreach ($result['items'] as $item) {
            $this->assertArrayHasKey('titre', $item['refs']);
            // Should NOT contain fields not in the filter
            $this->assertArrayNotHasKey('auteur', $item['refs']);
            $this->assertArrayNotHasKey('genre', $item['refs']);
            $this->assertArrayNotHasKey('annee', $item['refs']);
        }
    }

    public function testGetEntityWithFieldsFilter(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => $id,
            'fields' => ['titre', 'auteur'],
        ]);

        $this->assertEquals($id, $result['id']);
        $this->assertArrayHasKey('titre', $result['refs']);
        $this->assertArrayHasKey('auteur', $result['refs']);
        $this->assertArrayNotHasKey('genre', $result['refs']);
        $this->assertArrayNotHasKey('annee', $result['refs']);
    }

    // --- Count only ---

    public function testGetTripletsCountOnly(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $id,
            'count_only' => true,
        ]);

        $this->assertArrayHasKey('conceptId', $result);
        $this->assertEquals($id, $result['conceptId']);
        $this->assertArrayHasKey('counts', $result);
        $this->assertArrayNotHasKey('triplets', $result);
        $this->assertArrayHasKey('outgoing', $result['counts']);
        $this->assertArrayHasKey('incoming', $result['counts']);
        $this->assertArrayHasKey('total', $result['counts']);
        $this->assertGreaterThanOrEqual(2, $result['counts']['outgoing']); // at least is_a + contained_in_file
        $this->assertEquals(
            $result['counts']['outgoing'] + $result['counts']['incoming'],
            $result['counts']['total']
        );
    }

    public function testGetTripletsCountOnlyDirectionFiltered(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_get_triplets', [
            'conceptId' => $id,
            'count_only' => true,
            'direction' => 'outgoing',
        ]);

        $this->assertArrayHasKey('counts', $result);
        $this->assertGreaterThanOrEqual(2, $result['counts']['outgoing']);
        $this->assertEquals(0, $result['counts']['incoming']);
        $this->assertEquals($result['counts']['outgoing'], $result['counts']['total']);
    }

    // --- ListEntities ---

    public function testListEntities(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_list_entities', [
            'factory' => 'livres',
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertArrayHasKey('hasMore', $result);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['count']);
        $this->assertEquals(0, $result['offset']);
        $this->assertFalse($result['hasMore']);
    }

    public function testListEntitiesWithPagination(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_list_entities', [
            'factory' => 'livres',
            'limit' => 2,
            'offset' => 0,
        ]);

        $this->assertEquals(2, $result['count']);
        $this->assertEquals(3, $result['total']);
        $this->assertTrue($result['hasMore']);

        // Second page
        $result2 = $this->mcp->getToolRegistry()->call('sandra_list_entities', [
            'factory' => 'livres',
            'limit' => 2,
            'offset' => 2,
        ]);

        $this->assertEquals(1, $result2['count']);
        $this->assertFalse($result2['hasMore']);
    }

    public function testListEntitiesWithFieldsFilter(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_list_entities', [
            'factory' => 'livres',
            'fields' => ['titre'],
        ]);

        $this->assertEquals(3, $result['count']);
        foreach ($result['items'] as $item) {
            $this->assertArrayHasKey('titre', $item['refs']);
            $this->assertArrayNotHasKey('auteur', $item['refs']);
        }
    }

    public function testListEntitiesUnknownFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_list_entities', [
            'factory' => 'inexistant',
        ]);
    }

    // --- GetSchema ---

    public function testGetSchemaAllFactories(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_schema', []);

        $this->assertArrayHasKey('factories', $result);
        $this->assertArrayHasKey('livres', $result['factories']);

        $livresSchema = $result['factories']['livres'];
        $this->assertEquals('livre', $livresSchema['entityIsa']);
        $this->assertEquals(3, $livresSchema['entityCount']);
        $this->assertArrayHasKey('fields', $livresSchema);

        $fieldNames = array_column($livresSchema['fields'], 'name');
        $this->assertContains('titre', $fieldNames);
        $this->assertContains('auteur', $fieldNames);
        $this->assertContains('genre', $fieldNames);
        $this->assertContains('annee', $fieldNames);
    }

    public function testGetSchemaSingleFactory(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_schema', [
            'factory' => 'livres',
        ]);

        $this->assertArrayHasKey('factories', $result);
        $this->assertCount(1, $result['factories']);
        $this->assertArrayHasKey('livres', $result['factories']);
    }

    public function testGetSchemaWithSamples(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_schema', [
            'factory' => 'livres',
            'include_samples' => true,
        ]);

        $livresSchema = $result['factories']['livres'];
        $titreField = null;
        foreach ($livresSchema['fields'] as $f) {
            if ($f['name'] === 'titre') { $titreField = $f; break; }
        }
        $this->assertNotNull($titreField);
        $this->assertArrayHasKey('samples', $titreField);
        $this->assertNotEmpty($titreField['samples']);
    }

    public function testGetSchemaWithoutSamples(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_get_schema', [
            'factory' => 'livres',
            'include_samples' => false,
        ]);

        $livresSchema = $result['factories']['livres'];
        foreach ($livresSchema['fields'] as $f) {
            $this->assertArrayNotHasKey('samples', $f);
        }
    }

    public function testGetSchemaUnknownFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_get_schema', [
            'factory' => 'inexistant',
        ]);
    }

    // --- Search: empty query (list all) ---

    public function testSearchEmptyQueryListsAll(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => '',
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(3, $result['count']);
        $this->assertArrayHasKey('offset', $result);
        $this->assertArrayHasKey('hasMore', $result);
    }

    public function testSearchNoQueryListsAll(): void
    {
        // query omitted entirely
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
        ]);

        $this->assertEquals(3, $result['total']);
    }

    public function testSearchEmptyQueryWithPagination(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => '',
            'limit' => 2,
            'offset' => 0,
        ]);

        $this->assertEquals(2, $result['count']);
        $this->assertTrue($result['hasMore']);
    }

    // --- Search: LIKE wildcard ---

    public function testSearchLikeWildcard(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => '%Hugo%',
            'field' => 'auteur',
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertGreaterThanOrEqual(1, $result['count']);
        $foundAuteurs = array_column(array_column($result['items'], 'refs'), 'auteur');
        $this->assertContains('Victor Hugo', $foundAuteurs);
    }

    // --- DataStorage ---

    public function testCreateEntityWithStorage(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_entity', [
            'factory' => 'livres',
            'refs' => [
                'titre' => 'Article Test',
                'auteur' => 'Test',
            ],
            'storage' => 'Ceci est un long texte stocké dans DataStorage.',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('storage', $result);
        $this->assertEquals('Ceci est un long texte stocké dans DataStorage.', $result['storage']);
    }

    public function testGetEntityWithStorage(): void
    {
        // Create entity with storage
        $created = $this->mcp->getToolRegistry()->call('sandra_create_entity', [
            'factory' => 'livres',
            'refs' => ['titre' => 'Avec Storage', 'auteur' => 'Test'],
            'storage' => 'Contenu long texte ici.',
        ]);
        $id = $created['id'];

        // Get without include_storage (default) — should NOT have storage
        $result = $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => $id,
        ]);
        $this->assertArrayNotHasKey('storage', $result);

        // Get with include_storage — should have storage
        $result = $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => $id,
            'include_storage' => true,
        ]);
        $this->assertArrayHasKey('storage', $result);
        $this->assertEquals('Contenu long texte ici.', $result['storage']);
    }

    public function testUpdateEntityStorage(): void
    {
        $entities = $this->livres->getEntities();
        $first = reset($entities);
        $id = (int)$first->subjectConcept->idConcept;

        $result = $this->mcp->getToolRegistry()->call('sandra_update_entity', [
            'factory' => 'livres',
            'id' => $id,
            'storage' => 'Nouveau contenu storage.',
        ]);

        $this->assertArrayHasKey('storage', $result);
        $this->assertEquals('Nouveau contenu storage.', $result['storage']);

        // Verify via get_entity
        $fetched = $this->mcp->getToolRegistry()->call('sandra_get_entity', [
            'factory' => 'livres',
            'id' => $id,
            'include_storage' => true,
        ]);
        $this->assertEquals('Nouveau contenu storage.', $fetched['storage']);
    }

    // --- Cross-factory search ---

    public function testCrossFactorySearch(): void
    {
        // Register a second factory
        $auteursInit = new EntityFactory('auteur_cross', 'auteursCrossFile', $this->system);
        $auteursInit->createNew(['nom' => 'Roman Polanski', 'pays' => 'France']);
        $auteurs = new EntityFactory('auteur_cross', 'auteursCrossFile', $this->system);
        $auteurs->populateLocal();
        $this->mcp->register('auteurs_cross', $auteurs);
        $this->mcp->boot();

        // Search across ALL factories without specifying factory
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'query' => 'Roman',
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('factoriesSearched', $result);
        $this->assertGreaterThanOrEqual(2, $result['factoriesSearched']);
        $this->assertGreaterThanOrEqual(1, $result['total']);
        // Results should be grouped by factory name
        $this->assertIsArray($result['results']);
    }

    public function testFactoryScopedSearchStillWorks(): void
    {
        // Verify backward compatibility: factory-scoped search returns items/count/total format
        $result = $this->mcp->getToolRegistry()->call('sandra_search', [
            'factory' => 'livres',
            'query' => 'Roman',
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertArrayNotHasKey('factoriesSearched', $result);
        $this->assertGreaterThanOrEqual(2, $result['count']);
    }

    // --- CreateConcept ---

    public function testCreateConcept(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_concept', [
            'name' => 'mcp_test_concept_xyz',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertIsInt($result['id']);
        $this->assertEquals('mcp_test_concept_xyz', $result['name']);
        $this->assertTrue($result['created']);
    }

    public function testCreateConceptExisting(): void
    {
        // Create first
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'mcp_test_friendship']);

        // Create again — should return existing
        $result = $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'mcp_test_friendship']);
        $this->assertFalse($result['created']);
        $this->assertIsInt($result['id']);
    }

    public function testCreateConceptEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => '']);
    }

    // --- CreateTriplet ---

    public function testCreateTriplet(): void
    {
        // Create concepts first
        $god = $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'god']);
        $created = $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'created']);
        $universe = $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'the_universe']);

        $result = $this->mcp->getToolRegistry()->call('sandra_create_triplet', [
            'subject' => $god['id'],
            'verb' => $created['id'],
            'target' => $universe['id'],
        ]);

        $this->assertArrayHasKey('linkId', $result);
        $this->assertIsInt($result['linkId']);
        $this->assertEquals($god['id'], $result['subjectId']);
        $this->assertEquals($created['id'], $result['verbId']);
        $this->assertEquals($universe['id'], $result['targetId']);
    }

    public function testCreateTripletByShortname(): void
    {
        // Create concepts
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'alice']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'knows']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'bob']);

        // Create triplet using shortnames
        $result = $this->mcp->getToolRegistry()->call('sandra_create_triplet', [
            'subject' => 'alice',
            'verb' => 'knows',
            'target' => 'bob',
        ]);

        $this->assertArrayHasKey('linkId', $result);
        $this->assertIsInt($result['linkId']);
    }

    public function testCreateTripletUnknownConcept(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Concept not found');
        $this->mcp->getToolRegistry()->call('sandra_create_triplet', [
            'subject' => 'totally_nonexistent_concept_abc123',
            'verb' => 'totally_nonexistent_verb_abc123',
            'target' => 'totally_nonexistent_target_abc123',
        ]);
    }

    // --- CreateFactory ---

    public function testCreateFactory(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_factory', [
            'name' => 'product',
        ]);

        $this->assertEquals('product', $result['name']);
        $this->assertEquals('product', $result['entityIsa']);
        $this->assertEquals('product_file', $result['entityContainedIn']);
        $this->assertTrue($result['created']);

        // Verify it shows in list_factories
        $factories = $this->mcp->getToolRegistry()->call('sandra_list_factories', []);
        $names = array_column($factories, 'name');
        $this->assertContains('product', $names);
    }

    public function testCreateFactoryAlreadyExists(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_factory', [
            'name' => 'livres',
        ]);

        $this->assertFalse($result['created']);
        $this->assertEquals('livres', $result['name']);
    }

    public function testCreateFactoryCustomFile(): void
    {
        $result = $this->mcp->getToolRegistry()->call('sandra_create_factory', [
            'name' => 'invoice',
            'contained_in_file' => 'invoiceStorage',
        ]);

        $this->assertTrue($result['created']);
        $this->assertEquals('invoiceStorage', $result['entityContainedIn']);
    }

    // --- DeleteTriplet ---

    public function testDeleteTriplet(): void
    {
        // Create a triplet to delete
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'temp_a']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'temp_verb']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'temp_b']);

        $triplet = $this->mcp->getToolRegistry()->call('sandra_create_triplet', [
            'subject' => 'temp_a',
            'verb' => 'temp_verb',
            'target' => 'temp_b',
        ]);

        $result = $this->mcp->getToolRegistry()->call('sandra_delete_triplet', [
            'linkId' => $triplet['linkId'],
        ]);

        $this->assertTrue($result['deleted']);
        $this->assertEquals($triplet['linkId'], $result['linkId']);
    }

    public function testDeleteTripletAlreadyDeleted(): void
    {
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'del_a']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'del_v']);
        $this->mcp->getToolRegistry()->call('sandra_create_concept', ['name' => 'del_b']);

        $triplet = $this->mcp->getToolRegistry()->call('sandra_create_triplet', [
            'subject' => 'del_a',
            'verb' => 'del_v',
            'target' => 'del_b',
        ]);

        // Delete once
        $this->mcp->getToolRegistry()->call('sandra_delete_triplet', ['linkId' => $triplet['linkId']]);

        // Delete again
        $result = $this->mcp->getToolRegistry()->call('sandra_delete_triplet', ['linkId' => $triplet['linkId']]);
        $this->assertFalse($result['deleted']);
        $this->assertStringContainsString('already deleted', $result['message']);
    }

    public function testDeleteTripletNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->mcp->getToolRegistry()->call('sandra_delete_triplet', ['linkId' => 999999]);
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
