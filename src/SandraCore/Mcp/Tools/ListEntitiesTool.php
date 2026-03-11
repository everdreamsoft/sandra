<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class ListEntitiesTool implements McpToolInterface
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
        return 'sandra_list_entities';
    }

    public function description(): string
    {
        return 'List all entities in a factory with pagination. Use this to browse entities without knowing specific values. Returns entities sorted by most recent first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'Factory name to list entities from',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 20, max 200)',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset (default 0)',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: only return these reference fields',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'description' => 'Optional: reference field name to sort by',
                ],
                'sort_order' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort direction (default DESC = newest first)',
                ],
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'If true, include data storage (long text) for each entity. Default false.',
                ],
            ],
            'required' => ['factory'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name. Use sandra_list_factories to see available factories.");
        }

        $limit = min((int)($args['limit'] ?? 20), 200);
        $offset = (int)($args['offset'] ?? 0);
        $fields = $args['fields'] ?? null;
        $sortBy = $args['sort_by'] ?? null;
        $sortOrder = $args['sort_order'] ?? 'DESC';

        $factory = $this->factories[$name]['factory'];

        $listFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );

        $listFactory->populateLocal($limit, $offset, $sortOrder, $sortBy);
        $entities = $listFactory->getEntities();
        $total = $listFactory->countEntitiesOnRequest();

        $serializeOptions = $fields !== null ? ['fields' => $fields] : [];
        if (!empty($args['include_storage'])) {
            $serializeOptions['include_storage'] = true;
        }
        $items = [];
        foreach ($entities as $entity) {
            $items[] = EntitySerializer::serialize($entity, $serializeOptions);
        }

        return [
            'items' => $items,
            'count' => count($items),
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($offset + count($items)) < $total,
        ];
    }
}
