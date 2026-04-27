<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * Structured reference query over a factory.
 *
 * Unlike sandra_list_entities (which applies LIMIT/OFFSET to the full set
 * and only sorts within the returned window), this pushes filters + sort +
 * pagination into SQL via EntityFactory::populateFromRefQuery — so a
 * "top 10 by lastLogin" over 231 000 entities actually scans the full set
 * in one round trip and returns the true top 10.
 */
class QueryEntitiesTool implements McpToolInterface
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
        return 'sandra_query_entities';
    }

    public function description(): string
    {
        return 'Structured query over a factory: AND-combined reference filters '
             . '(=, !=, >, >=, <, <=, LIKE, IN) with optional sort on any ref '
             . '(numeric or lexicographic) and pagination — all pushed to SQL. '
             . 'Use this instead of sandra_list_entities when you need the true '
             . 'top-N of a large factory, or any filter beyond a single brother '
             . 'relationship. Combines with brother_filters.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'Factory name to query entities from',
                ],
                'filters' => [
                    'type' => 'array',
                    'description' => 'AND-combined reference filters. Each filter tests '
                                   . 'one reference field with one operator. Numeric '
                                   . 'operators (>, >=, <, <=) automatically CAST the '
                                   . 'value column — safe for string-stored numbers.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ref' => [
                                'type' => 'string',
                                'description' => 'Reference field shortname (e.g. "lastLogin", "price")',
                            ],
                            'op' => [
                                'type' => 'string',
                                'enum' => ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN'],
                                'description' => 'Comparison operator. Use LIKE with % wildcards '
                                               . 'for substring; IN with an array value for set membership.',
                            ],
                            'value' => [
                                'description' => 'Scalar for most ops; array for IN.',
                            ],
                        ],
                        'required' => ['ref', 'op', 'value'],
                    ],
                ],
                'sort' => [
                    'type' => 'object',
                    'description' => 'Optional ORDER BY on a reference field. The field '
                                   . 'does not need to appear in filters.',
                    'properties' => [
                        'ref' => [
                            'type' => 'string',
                            'description' => 'Reference shortname to sort by',
                        ],
                        'direction' => [
                            'type' => 'string',
                            'enum' => ['ASC', 'DESC'],
                            'description' => 'Default ASC',
                        ],
                        'numeric' => [
                            'type' => 'boolean',
                            'description' => 'If true, CAST values as numbers before sorting. '
                                           . 'Essential for timestamps, prices, levels — '
                                           . 'string sort on mixed-length digits gives wrong order.',
                        ],
                    ],
                    'required' => ['ref'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 20, max 200)',
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
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'If true, include data storage (long text). Default false.',
                ],
            ],
            'required' => ['factory', 'filters'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException(
                "Unknown factory: $name. Use sandra_list_factories to see available factories."
            );
        }

        $filters = $args['filters'] ?? [];
        if (!is_array($filters) || empty($filters)) {
            throw new \InvalidArgumentException(
                'filters must be a non-empty array. For an unfiltered browse, use sandra_list_entities.'
            );
        }

        foreach ($filters as $i => $f) {
            if (!is_array($f) || !isset($f['ref'], $f['op'])) {
                throw new \InvalidArgumentException(
                    "filters[$i] must be an object with 'ref', 'op', and 'value'."
                );
            }
        }

        $sort = $args['sort'] ?? null;
        if ($sort !== null && (!is_array($sort) || empty($sort['ref']))) {
            throw new \InvalidArgumentException("sort must be an object with a 'ref' field.");
        }

        $limit = min((int)($args['limit'] ?? 20), 200);
        $offset = (int)($args['offset'] ?? 0);
        $fields = $args['fields'] ?? null;

        $factory = $this->factories[$name]['factory'];

        // Fresh factory so the query does not inherit cached state from prior calls.
        $queryFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );

        $entities = $queryFactory->populateFromRefQuery($filters, $sort, $limit, $offset);

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
            'offset' => $offset,
            'limit' => $limit,
            // No cheap total for structured queries — a full COUNT(DISTINCT) over
            // the join set is not free. hasMore is approximated: if we filled the
            // page, there might be more. Callers needing exact totals should paginate.
            'hasMore' => count($items) >= $limit,
        ];
    }
}
