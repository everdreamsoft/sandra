<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class SemanticSearchTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private System $system;
    private EmbeddingService $embeddingService;

    public function __construct(array &$factories, System $system, EmbeddingService $embeddingService)
    {
        $this->factories = &$factories;
        $this->system = $system;
        $this->embeddingService = $embeddingService;
    }

    public function name(): string
    {
        return 'sandra_semantic_search';
    }

    public function description(): string
    {
        return 'Semantic search across entities using natural language. '
            . 'Embeds the query and finds the closest entities by meaning (cosine similarity). '
            . 'Unlike sandra_search (exact/LIKE match), this finds conceptually similar entities '
            . 'even when exact keywords don\'t match.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural language query to search for semantically similar entities.',
                ],
                'factory' => [
                    'type' => 'string',
                    'description' => 'Optional: restrict search to entities in this factory.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 10)',
                ],
                'threshold' => [
                    'type' => 'number',
                    'description' => 'Minimum similarity score 0-1 (default 0.3)',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: only return these reference fields.',
                ],
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'Include data storage in results. Default false.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): mixed
    {
        $query = trim($args['query'] ?? '');
        if ($query === '') {
            throw new \InvalidArgumentException('Query is required for semantic search.');
        }

        $factoryFilter = $args['factory'] ?? null;
        $limit = (int)($args['limit'] ?? 10);
        $threshold = (float)($args['threshold'] ?? 0.2);
        $fields = $args['fields'] ?? null;
        $includeStorage = !empty($args['include_storage']);

        $scored = $this->embeddingService->searchSimilar($query, $limit * 2, $factoryFilter);

        // Filter by threshold
        $scored = array_filter($scored, fn($item) => $item['similarity'] >= $threshold);
        $scored = array_slice(array_values($scored), 0, $limit);

        if (empty($scored)) {
            return [
                'results' => [],
                'total' => 0,
                'query' => $query,
            ];
        }

        $serializeOptions = $fields !== null ? ['fields' => $fields] : [];
        if ($includeStorage) {
            $serializeOptions['include_storage'] = true;
        }

        $results = [];
        foreach ($scored as $item) {
            $conceptId = $item['conceptId'];
            $factoryName = $this->findFactoryForConcept($conceptId);

            if ($factoryName === null) {
                continue;
            }

            $factoryEntry = $this->factories[$factoryName] ?? null;
            if ($factoryEntry === null) {
                continue;
            }

            $factory = $factoryEntry['factory'];
            $loadFactory = new EntityFactory(
                $factory->entityIsa,
                $factory->entityContainedIn,
                $this->system
            );
            $loadFactory->conceptArray = [$conceptId];
            $loadFactory->populateLocal();
            $entities = $loadFactory->getEntities();

            if (empty($entities)) {
                continue;
            }

            $entity = reset($entities);
            $serialized = EntitySerializer::serialize($entity, $serializeOptions);
            $serialized['similarity'] = $item['similarity'];
            $serialized['type'] = 'entity';
            $serialized['factory'] = $factoryName;
            $results[] = $serialized;
        }

        return [
            'results' => $results,
            'total' => count($results),
            'query' => $query,
        ];
    }

    /**
     * Find which factory a concept belongs to by checking its is_a triplet.
     */
    private function findFactoryForConcept(int $conceptId): ?string
    {
        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $conceptTable = $this->system->conceptTable;
        $isaId = $this->system->systemConcept->get('is_a');

        $sql = "SELECT c.shortname
                FROM `$linkTable` t
                JOIN `$conceptTable` c ON c.id = t.idConceptTarget
                WHERE t.idConceptStart = :conceptId
                  AND t.idConceptLink = :isaId
                LIMIT 1";

        $rows = \SandraCore\QueryExecutor::fetchAll($pdo, $sql, [
            ':conceptId' => [$conceptId, \PDO::PARAM_INT],
            ':isaId' => [$isaId, \PDO::PARAM_INT],
        ]);

        if (empty($rows)) {
            return null;
        }

        $isaName = $rows[0]['shortname'];

        foreach ($this->factories as $name => $entry) {
            if ($entry['factory']->entityIsa === $isaName) {
                return $name;
            }
        }

        return null;
    }
}
