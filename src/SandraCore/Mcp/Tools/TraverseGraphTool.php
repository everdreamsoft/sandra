<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Graph\GraphTraverser;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class TraverseGraphTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private System $system;

    public function __construct(array &$factories, System $system)
    {
        $this->factories = &$factories;
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_traverse';
    }

    public function description(): string
    {
        return 'Traverse the graph from a starting entity following a verb link. Supports BFS, DFS, and ancestor (backward) traversal. Does NOT load the entire factory into memory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name',
                ],
                'startId' => [
                    'type' => 'integer',
                    'description' => 'Concept ID of the starting entity',
                ],
                'verb' => [
                    'type' => 'string',
                    'description' => 'The verb (relationship type) to follow',
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Maximum traversal depth (default 10)',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['forward', 'backward'],
                    'description' => 'Traversal direction (default forward)',
                ],
                'algorithm' => [
                    'type' => 'string',
                    'enum' => ['bfs', 'dfs'],
                    'description' => 'Traversal algorithm (default bfs)',
                ],
            ],
            'required' => ['factory', 'startId', 'verb'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $startId = (int)($args['startId'] ?? 0);
        $verb = $args['verb'] ?? '';
        $depth = (int)($args['depth'] ?? 10);
        $direction = $args['direction'] ?? 'forward';
        $algorithm = $args['algorithm'] ?? 'bfs';

        // Use a fresh factory with a safety limit to avoid OOM on large factories.
        // Traversal needs all connected entities in memory, but we cap the load.
        $maxEntities = 5000;
        $traverseFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $traverseFactory->populateLocal($maxEntities);
        $traverseFactory->getTriplets();

        $startEntity = null;
        foreach ($traverseFactory->getEntities() as $e) {
            if ((int)$e->subjectConcept->idConcept === $startId) {
                $startEntity = $e;
                break;
            }
        }

        if ($startEntity === null) {
            throw new \InvalidArgumentException("Entity with id $startId not found in factory '$name' (loaded up to $maxEntities entities)");
        }

        $traverser = new GraphTraverser($this->system);

        if ($direction === 'backward') {
            $traversalResult = $traverser->ancestors($startEntity, $verb, $depth);
        } elseif ($algorithm === 'dfs') {
            $traversalResult = $traverser->dfs($startEntity, $verb, $depth);
        } else {
            $traversalResult = $traverser->bfs($startEntity, $verb, $depth);
        }

        $entities = [];
        foreach ($traversalResult->getEntities() as $entity) {
            $entities[] = EntitySerializer::serialize($entity);
        }

        return [
            'entities' => $entities,
            'hasCycle' => $traversalResult->hasCycle(),
            'totalFound' => count($entities),
        ];
    }
}
