<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use PHPUnit\Framework\TestCase;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\Tools\SemanticSearchTool;
use SandraCore\Mcp\Tools\CreateEntityTool;
use SandraCore\Mcp\Tools\UpdateEntityTool;
use SandraCore\System;

/**
 * Testable subclass that overrides the OpenAI API call.
 * Returns a deterministic fake embedding based on text content.
 */
class FakeEmbeddingService extends EmbeddingService
{
    public int $apiCallCount = 0;
    private bool $shouldFail = false;

    public function __construct(System $system)
    {
        parent::__construct($system, 'fake-api-key');
    }

    public function getEmbedding(string $text): array
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Simulated API failure');
        }

        $this->apiCallCount++;

        // Generate a deterministic 16-dim vector from text hash
        // (using 16 dims instead of 1536 for test speed)
        $hash = md5($text);
        $vector = [];
        for ($i = 0; $i < 16; $i++) {
            $vector[] = (float)(hexdec(substr($hash, $i * 2, 2)) - 128) / 128.0;
        }

        // Normalize
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm > 0) {
            $vector = array_map(fn($v) => $v / $norm, $vector);
        }

        return $vector;
    }

    public function setShouldFail(bool $fail): void
    {
        $this->shouldFail = $fail;
    }
}

class EmbeddingServiceTest extends TestCase
{
    private System $system;
    private FakeEmbeddingService $service;
    private EntityFactory $factory;

    protected function setUp(): void
    {
        $this->system = new System('phpUnitEmbed', true);
        \SandraCore\Setup::flushDatagraph($this->system);
        $this->system = new System('phpUnitEmbed', true);

        $this->service = new FakeEmbeddingService($this->system);
        $this->factory = new EntityFactory('animal', 'animal_file', $this->system);
    }

    // --- Cosine Similarity Tests ---

    public function testCosineSimilarityIdentical(): void
    {
        $v = [1.0, 0.0, 0.5, -0.3];
        $this->assertEqualsWithDelta(1.0, $this->service->cosineSimilarity($v, $v), 0.0001);
    }

    public function testCosineSimilarityOrthogonal(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];
        $this->assertEqualsWithDelta(0.0, $this->service->cosineSimilarity($a, $b), 0.0001);
    }

    public function testCosineSimilarityOpposite(): void
    {
        $a = [1.0, 0.5];
        $b = [-1.0, -0.5];
        $this->assertEqualsWithDelta(-1.0, $this->service->cosineSimilarity($a, $b), 0.0001);
    }

    public function testCosineSimilarityEmpty(): void
    {
        $this->assertEquals(0.0, $this->service->cosineSimilarity([], []));
    }

    // --- Build Entity Text Tests ---

    public function testBuildEntityText(): void
    {
        $entity = $this->factory->createNew(['name' => 'Fido', 'breed' => 'Labrador']);
        $entity->setStorage('A friendly golden lab who loves fetch.');

        $text = $this->service->buildEntityText($entity);

        $this->assertStringContainsString('name: Fido', $text);
        $this->assertStringContainsString('breed: Labrador', $text);
        $this->assertStringContainsString('A friendly golden lab', $text);
        $this->assertStringContainsString('---', $text);
    }

    public function testBuildEntityTextWithoutStorage(): void
    {
        $entity = $this->factory->createNew(['name' => 'Rex', 'type' => 'Dog']);

        $text = $this->service->buildEntityText($entity);

        $this->assertStringContainsString('name: Rex', $text);
        $this->assertStringNotContainsString('---', $text);
    }

    // --- Store & Retrieve Tests ---

    public function testStoreAndRetrieveEmbedding(): void
    {
        $vector = [0.1, 0.2, 0.3, 0.4];
        $this->service->storeEmbedding(999, $vector, 'testhash123');

        $hash = $this->service->getTextHash(999);
        $this->assertEquals('testhash123', $hash);
    }

    public function testGetTextHashReturnsNullForMissing(): void
    {
        $this->assertNull($this->service->getTextHash(99999));
    }

    // --- Hash Skip Tests ---

    public function testTextHashSkipsReEmbed(): void
    {
        $entity = $this->factory->createNew(['name' => 'Buddy']);

        $this->service->embedEntity($entity);
        $this->assertEquals(1, $this->service->apiCallCount);

        // Same entity, same text → should skip
        $this->service->embedEntity($entity);
        $this->assertEquals(1, $this->service->apiCallCount);
    }

    public function testTextHashReEmbedsOnChange(): void
    {
        $entity = $this->factory->createNew(['name' => 'Buddy']);

        $this->service->embedEntity($entity);
        $this->assertEquals(1, $this->service->apiCallCount);

        // Change entity storage → should re-embed
        $entity->setStorage('Now has new info');
        $this->service->embedEntity($entity);
        $this->assertEquals(2, $this->service->apiCallCount);
    }

    // --- isAvailable Tests ---

    public function testIsAvailableWithKey(): void
    {
        $service = new EmbeddingService($this->system, 'sk-test-key');
        $this->assertTrue($service->isAvailable());
    }

    public function testIsAvailableWithoutKey(): void
    {
        $service = new EmbeddingService($this->system, '');
        $this->assertFalse($service->isAvailable());
    }

    // --- Semantic Search Tests ---

    public function testSearchReturnsRankedResults(): void
    {
        // Create entities and embed them
        $dog = $this->factory->createNew(['name' => 'Golden Retriever', 'type' => 'dog']);
        $cat = $this->factory->createNew(['name' => 'Persian Cat', 'type' => 'cat']);
        $fish = $this->factory->createNew(['name' => 'Tropical Fish', 'type' => 'fish']);

        $this->service->embedEntity($dog);
        $this->service->embedEntity($cat);
        $this->service->embedEntity($fish);

        $results = $this->service->searchSimilar('Golden Retriever dog', 10);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('conceptId', $results[0]);
        $this->assertArrayHasKey('similarity', $results[0]);

        // First result should be the most similar
        // Results should be sorted descending by similarity
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]['similarity'], $results[$i - 1]['similarity']);
        }
    }

    public function testSearchReturnsEmptyForNoEmbeddings(): void
    {
        // No entities embedded yet
        $results = $this->service->searchSimilar('anything', 10);
        $this->assertEmpty($results);
    }

    // --- SemanticSearchTool Tests ---

    public function testSemanticSearchToolReturnsCorrectFormat(): void
    {
        $factories = [
            'animal' => [
                'factory' => $this->factory,
                'options' => [],
            ],
        ];

        $dog = $this->factory->createNew(['name' => 'Rex', 'breed' => 'Shepherd']);
        $this->service->embedEntity($dog);

        $tool = new SemanticSearchTool($factories, $this->system, $this->service);
        $result = $tool->execute(['query' => 'shepherd dog', 'threshold' => 0.0]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('query', $result);

        if (!empty($result['results'])) {
            $first = $result['results'][0];
            $this->assertArrayHasKey('id', $first);
            $this->assertArrayHasKey('refs', $first);
            $this->assertArrayHasKey('similarity', $first);
            $this->assertArrayHasKey('type', $first);
            $this->assertArrayHasKey('factory', $first);
            $this->assertEquals('entity', $first['type']);
            $this->assertEquals('animal', $first['factory']);
        }
    }

    public function testSemanticSearchToolRespectsThreshold(): void
    {
        $factories = [
            'animal' => [
                'factory' => $this->factory,
                'options' => [],
            ],
        ];

        $dog = $this->factory->createNew(['name' => 'Rex']);
        $this->service->embedEntity($dog);

        $tool = new SemanticSearchTool($factories, $this->system, $this->service);

        // With extremely high threshold, should return no results
        $result = $tool->execute(['query' => 'something completely different', 'threshold' => 0.999]);
        $this->assertEquals(0, $result['total']);
    }

    // --- Integration: Create/Update hooks ---

    public function testCreateEntityTriggersEmbedding(): void
    {
        $factories = [
            'animal' => [
                'factory' => $this->factory,
                'options' => [],
            ],
        ];
        $factoryMeta = [
            'animal' => [
                'isa' => 'animal',
                'cif' => 'animal_file',
                'options' => [],
            ],
        ];

        $tool = new CreateEntityTool($factories, $factoryMeta, $this->system, $this->service);
        $tool->execute(['factory' => 'animal', 'refs' => ['name' => 'TestDog']]);

        $this->assertEquals(1, $this->service->apiCallCount);
    }

    public function testEmbeddingFailureDoesNotBlockEntityCreation(): void
    {
        $this->service->setShouldFail(true);

        $factories = [
            'animal' => [
                'factory' => $this->factory,
                'options' => [],
            ],
        ];
        $factoryMeta = [
            'animal' => [
                'isa' => 'animal',
                'cif' => 'animal_file',
                'options' => [],
            ],
        ];

        $tool = new CreateEntityTool($factories, $factoryMeta, $this->system, $this->service);
        $result = $tool->execute(['factory' => 'animal', 'refs' => ['name' => 'FailDog']]);

        // Entity should still be created despite embedding failure
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('refs', $result);
        $this->assertEquals('FailDog', $result['refs']['name']);
    }
}
