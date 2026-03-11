<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\Search\SqlSearch;
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
        return 'Universal search across the Sandra graph. Searches both entities (tabular data like clients, products) '
            . 'and system concepts (abstract vocabulary like "healthy", "is_a", "friend"). '
            . 'Each result is tagged with type "entity" or "system_concept". '
            . 'Supports exact match, partial match (LIKE with %), or empty query to list all. '
            . 'Case-insensitive. If factory is given, searches only entities in that factory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'Optional: factory name to search entities in. If omitted, searches all factories AND system concepts.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Value to search for. Use % for wildcards (e.g. "%dupont%"). Empty string or omitted = list all entities.',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Optional: restrict entity search to this reference field shortname (e.g. "nom", "email")',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 50)',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset (default 0)',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: list of ref field names to include in entity response. If omitted, all fields are returned.',
                ],
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'If true, include data storage (long text) for each entity. Default false.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? null;
        $query = trim($args['query'] ?? '');
        $limit = (int)($args['limit'] ?? 50);
        $offset = (int)($args['offset'] ?? 0);
        $field = $args['field'] ?? null;
        $fields = $args['fields'] ?? null;
        $isLike = str_contains($query, '%');
        $includeStorage = !empty($args['include_storage']);

        if ($name !== null) {
            return $this->searchSingleFactory($name, $query, $field, $limit, $offset, $fields, $isLike, $includeStorage);
        }

        return $this->searchAllFactories($query, $field, $limit, $offset, $fields, $isLike, $includeStorage);
    }

    private function searchSingleFactory(string $name, string $query, ?string $field, int $limit, int $offset, ?array $fields, bool $isLike, bool $includeStorage): array
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name. Use sandra_list_factories to see available factories.");
        }

        $factory = $this->factories[$name]['factory'];

        $searchFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );

        // Empty query = list all entities
        if ($query === '') {
            $searchFactory->populateLocal($limit, $offset, 'DESC');
            $entities = $searchFactory->getEntities();
            $total = $searchFactory->countEntitiesOnRequest();
        } elseif ($isLike && $field !== null) {
            // LIKE search on a specific field via searchConceptByRef
            $linkConceptId = $this->system->systemConcept->get($searchFactory->entityReferenceContainer);
            $targetConceptId = $this->system->systemConcept->get($searchFactory->entityContainedIn);
            $conceptIds = \SandraCore\DatabaseAdapter::searchConceptByRef(
                $this->system,
                $field,
                'LIKE',
                $query,
                $linkConceptId ?: '',
                $targetConceptId ?: '',
                $limit
            );
            if (!empty($conceptIds)) {
                $searchFactory->conceptArray = $conceptIds;
                $searchFactory->populateLocal();
            }
            $entities = $searchFactory->getEntities();
            $total = count($entities);
        } elseif ($isLike) {
            // LIKE search across ALL reference fields (case-insensitive via SqlSearch)
            $sqlSearch = new SqlSearch($this->system);
            $entities = $sqlSearch->search($searchFactory, $query, $limit);
            $total = count($entities);
        } else {
            // Exact search
            $refConceptId = 0;
            if ($field !== null) {
                $refConceptId = $this->system->systemConcept->get($field, null, false);
                if ($refConceptId === null) {
                    $refConceptId = 0;
                }
            }
            $searchFactory->populateFromSearchResults($query, $refConceptId, $limit);
            $entities = $searchFactory->getEntities();
            $total = count($entities);
        }

        $serializeOptions = $fields !== null ? ['fields' => $fields] : [];
        if ($includeStorage) {
            $serializeOptions['include_storage'] = true;
        }
        $items = [];
        foreach ($entities as $entity) {
            $serialized = EntitySerializer::serialize($entity, $serializeOptions);
            $serialized['type'] = 'entity';
            $serialized['factory'] = $name;
            $items[] = $serialized;
        }

        $result = [
            'items' => $items,
            'count' => count($items),
            'total' => $total,
        ];

        if ($query === '') {
            $result['offset'] = $offset;
            $result['hasMore'] = ($offset + count($items)) < $total;
        }

        return $result;
    }

    private function searchAllFactories(string $query, ?string $field, int $limit, int $offset, ?array $fields, bool $isLike, bool $includeStorage): array
    {
        $serializeOptions = $fields !== null ? ['fields' => $fields] : [];
        if ($includeStorage) {
            $serializeOptions['include_storage'] = true;
        }

        $allItems = [];

        // --- Search system concepts first (fast, in-memory + small SQL) ---
        if ($query !== '') {
            $conceptResults = $this->searchConcepts($query, $isLike, $limit);
            foreach ($conceptResults as $concept) {
                $allItems[] = $concept;
            }
        }

        // --- Search entities across all factories ---
        $entityCount = 0;
        $entityLimit = $limit - count($allItems);

        foreach ($this->factories as $factoryName => $entry) {
            if ($entityCount >= $entityLimit) {
                break;
            }

            $factory = $entry['factory'];
            $searchFactory = new EntityFactory(
                $factory->entityIsa,
                $factory->entityContainedIn,
                $this->system
            );

            $remaining = $entityLimit - $entityCount;

            if ($query === '') {
                // List all
                $searchFactory->populateLocal($remaining, 0, 'DESC');
                $entities = $searchFactory->getEntities();
            } elseif ($isLike && $field !== null) {
                $linkConceptId = $this->system->systemConcept->get($searchFactory->entityReferenceContainer);
                $targetConceptId = $this->system->systemConcept->get($searchFactory->entityContainedIn);
                $conceptIds = \SandraCore\DatabaseAdapter::searchConceptByRef(
                    $this->system,
                    $field,
                    'LIKE',
                    $query,
                    $linkConceptId ?: '',
                    $targetConceptId ?: '',
                    $remaining
                );
                if (!empty($conceptIds)) {
                    $searchFactory->conceptArray = $conceptIds;
                    $searchFactory->populateLocal();
                }
                $entities = $searchFactory->getEntities();
            } elseif ($isLike) {
                // LIKE search across ALL reference fields (case-insensitive via SqlSearch)
                $sqlSearch = new SqlSearch($this->system);
                $entities = $sqlSearch->search($searchFactory, $query, $remaining);
            } else {
                $refConceptId = 0;
                if ($field !== null) {
                    $refConceptId = $this->system->systemConcept->get($field, null, false);
                    if ($refConceptId === null) {
                        $refConceptId = 0;
                    }
                }
                $searchFactory->populateFromSearchResults($query, $refConceptId, $remaining);
                $entities = $searchFactory->getEntities();
            }

            if (!empty($entities)) {
                foreach ($entities as $entity) {
                    $serialized = EntitySerializer::serialize($entity, $serializeOptions);
                    $serialized['type'] = 'entity';
                    $serialized['factory'] = $factoryName;
                    $allItems[] = $serialized;
                }
                $entityCount += count($entities);
            }
        }

        return [
            'results' => $allItems,
            'total' => count($allItems),
            'factoriesSearched' => count($this->factories),
        ];
    }

    /**
     * Search system concepts by shortname (case-insensitive).
     *
     * @return array<array{type: string, id: int, shortname: string}>
     */
    private function searchConcepts(string $query, bool $isLike, int $limit): array
    {
        $pdo = $this->system->getConnection();
        $conceptTable = $this->system->conceptTable;

        if ($isLike) {
            $pattern = mb_strtolower($query);
        } else {
            $pattern = '%' . mb_strtolower($query) . '%';
        }

        $sql = "SELECT id, shortname FROM `{$conceptTable}` WHERE LOWER(shortname) LIKE :query AND shortname != '' ORDER BY shortname ASC LIMIT :limit";
        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':query' => [$pattern, PDO::PARAM_STR],
            ':limit' => [$limit, PDO::PARAM_INT],
        ]);

        $results = [];
        if ($rows) {
            foreach ($rows as $row) {
                $results[] = [
                    'type' => 'system_concept',
                    'id' => (int)$row['id'],
                    'shortname' => $row['shortname'],
                ];
            }
        }

        return $results;
    }
}
