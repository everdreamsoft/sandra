<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class SearchEntitiesTool implements McpToolInterface
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
        return 'sandra_search';
    }

    public function description(): string
    {
        return 'Search entities by value. If factory is given, searches within that factory using DB-level search (efficient on large datasets). If field is given, restricts to that reference field. Does NOT load all entities into memory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name to search in',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Exact value to search for (matched at DB level)',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Optional: restrict search to this reference field shortname',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 50)',
                ],
            ],
            'required' => ['factory', 'query'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $query = $args['query'] ?? '';
        $limit = (int)($args['limit'] ?? 50);
        $field = $args['field'] ?? null;

        // Use a fresh factory to avoid polluting the shared instance
        $searchFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );

        // DB-level search: only loads matching entities, not the whole factory
        $refConceptId = 0;
        if ($field !== null) {
            $refConceptId = $this->system->systemConcept->get($field, null, false);
            if ($refConceptId === null) {
                $refConceptId = 0;
            }
        }

        $searchFactory->populateFromSearchResults($query, $refConceptId, $limit);
        $entities = $searchFactory->getEntities();

        $items = [];
        foreach ($entities as $entity) {
            $items[] = EntitySerializer::serialize($entity);
        }

        return [
            'items' => $items,
            'total' => count($items),
        ];
    }
}
