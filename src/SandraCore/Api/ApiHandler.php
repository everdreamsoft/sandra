<?php
declare(strict_types=1);

namespace SandraCore\Api;

use SandraCore\DatabaseAdapter;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use PDO;
use SandraCore\QueryExecutor;
use SandraCore\Search\BasicSearch;
use SandraCore\System;
use SandraCore\Validation\ValidationException;

class ApiHandler
{
    private System $system;

    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $routes = [];

    private static array $defaultOptions = [
        'read' => true,
        'create' => true,
        'update' => true,
        'delete' => true,
        'searchable' => [],
        'brothers' => [],
        'joined' => [],
    ];

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function register(string $name, EntityFactory $factory, array $options = []): self
    {
        $mergedOptions = array_merge(self::$defaultOptions, $options);
        $this->routes[$name] = [
            'factory' => $factory,
            'options' => $mergedOptions,
        ];

        if (!empty($mergedOptions['joined'])) {
            foreach ($mergedOptions['joined'] as $verb => $joinedFactory) {
                $factory->joinFactory($verb, $joinedFactory);
            }
        }

        return $this;
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $path = trim($request->getPath(), '/');
        $segments = $path !== '' ? explode('/', $path) : [];

        if (empty($segments)) {
            return new ApiResponse(404, [], 'Route not found');
        }

        $resourceName = $segments[0];
        $resourceId = $segments[1] ?? null;

        if (!isset($this->routes[$resourceName])) {
            return new ApiResponse(404, [], "Resource '$resourceName' not found");
        }

        $route = $this->routes[$resourceName];
        $factory = $route['factory'];
        $options = $route['options'];

        return match ($request->getMethod()) {
            'GET' => $this->handleGet($factory, $options, $resourceId, $request),
            'POST' => $this->handlePost($factory, $options, $request),
            'PUT' => $this->handlePut($factory, $options, $resourceId, $request),
            'DELETE' => $this->handleDelete($factory, $options, $resourceId),
            default => new ApiResponse(405, [], "Method {$request->getMethod()} not allowed"),
        };
    }

    private function handleGet(EntityFactory $factory, array $options, ?string $id, ApiRequest $request): ApiResponse
    {
        if (!$options['read']) {
            return new ApiResponse(405, [], 'Read not allowed on this resource');
        }

        $query = $request->getQuery();
        $includeStorage = $this->parseBoolQuery($query['include_storage'] ?? null);

        // Single entity by ID — load only that concept
        if ($id !== null) {
            $factory->conceptArray = [(int)$id];
            $factory->populateLocal();
            $entity = $this->findEntityById($factory, (int)$id);
            if ($entity === null) {
                return new ApiResponse(404, [], "Entity with id $id not found");
            }
            if (!empty($options['joined'])) {
                $factory->joinPopulate();
            }
            return new ApiResponse(200, $this->serializeEntity($entity, $options, $includeStorage));
        }

        // Search
        if (isset($query['search']) && !empty($options['searchable'])) {
            if (!$factory->isPopulated()) {
                $factory->populateLocal();
            }
            return $this->handleSearch($factory, $options, $query['search'], $query, $includeStorage);
        }

        // List with pagination
        $limit = isset($query['limit']) ? (int)$query['limit'] : 50;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;

        if ($factory->isPopulated()) {
            // Factory already populated (e.g. by caller) — slice in memory
            $entities = $factory->getEntities();
            $total = count($entities);
            $slice = array_slice(array_values($entities), $offset, $limit);
        } else {
            // Fresh factory — only load the requested page from DB
            $total = $factory->countEntitiesOnRequest();
            $factory->populateLocal($limit, $offset);
            $slice = array_values($factory->getEntities());
        }

        if (!empty($options['joined'])) {
            $factory->joinPopulate();
        }

        // Batch-fetch storage for the visible slice in a single query when requested.
        $storageByLinkId = $includeStorage ? $this->batchFetchStorage($slice) : [];

        $items = array_map(
            fn(Entity $e) => $this->serializeEntity($e, $options, $includeStorage, $storageByLinkId),
            $slice
        );

        return new ApiResponse(200, [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function handlePost(EntityFactory $factory, array $options, ApiRequest $request): ApiResponse
    {
        if (!$options['create']) {
            return new ApiResponse(405, [], 'Create not allowed on this resource');
        }

        $body = $request->getBody();
        if (empty($body)) {
            return new ApiResponse(422, [], 'Request body is empty');
        }

        $brothersData = $body['brothers'] ?? [];
        unset($body['brothers']);
        $joinedData = $body['joined'] ?? [];
        unset($body['joined']);
        $storageProvided = array_key_exists('storage', $body);
        $storageValue = $storageProvided ? (string)$body['storage'] : null;
        unset($body['storage']);

        try {
            $entity = $factory->createNew($body);
        } catch (ValidationException $e) {
            return new ApiResponse(422, ['errors' => $e->getErrors()], $e->getFirstError());
        } catch (\Exception $e) {
            return new ApiResponse(422, [], $e->getMessage());
        }

        if ($storageProvided && $storageValue !== '') {
            DatabaseAdapter::setStorage($entity, $storageValue);
        }

        $allowedBrothers = $options['brothers'] ?? [];
        if (!empty($brothersData) && !empty($allowedBrothers)) {
            foreach ($brothersData as $verb => $entries) {
                if (in_array($verb, $allowedBrothers, true)) {
                    foreach ($entries as $entry) {
                        $target = $entry['target'] ?? null;
                        $refs = $entry['refs'] ?? [];
                        if ($target !== null) {
                            $entity->setBrotherEntity($verb, $target, $refs);
                        }
                    }
                }
            }
        }

        $linkedJoined = [];
        $allowedJoined = $options['joined'] ?? [];
        if (!empty($joinedData) && !empty($allowedJoined)) {
            foreach ($joinedData as $verb => $ids) {
                if (isset($allowedJoined[$verb])) {
                    $joinedFactory = $allowedJoined[$verb];
                    if (!$joinedFactory->isPopulated()) {
                        $joinedFactory->populateLocal();
                    }
                    foreach ($ids as $conceptId) {
                        $targetEntity = $this->findEntityById($joinedFactory, (int)$conceptId);
                        if ($targetEntity !== null) {
                            $entity->setJoinedEntity($verb, $targetEntity, []);
                            $linkedJoined[$verb][] = $targetEntity;
                        }
                    }
                }
            }
        }

        $result = $this->serializeEntity($entity, $options, $storageProvided);
        if (!empty($linkedJoined)) {
            $result['joined'] = $this->serializeLinkedJoined($linkedJoined, $options);
        }

        return new ApiResponse(201, $result);
    }

    private function handlePut(EntityFactory $factory, array $options, ?string $id, ApiRequest $request): ApiResponse
    {
        if (!$options['update']) {
            return new ApiResponse(405, [], 'Update not allowed on this resource');
        }

        if ($id === null) {
            return new ApiResponse(422, [], 'Entity ID is required for update');
        }

        if (!$factory->isPopulated()) {
            $factory->populateLocal();
        }

        if (!empty($options['joined'])) {
            $factory->joinPopulate();
        }

        $entity = $this->findEntityById($factory, (int)$id);
        if ($entity === null) {
            return new ApiResponse(404, [], "Entity with id $id not found");
        }

        $body = $request->getBody();
        $brothersData = $body['brothers'] ?? [];
        unset($body['brothers']);
        $joinedData = $body['joined'] ?? [];
        unset($body['joined']);
        $storageProvided = array_key_exists('storage', $body);
        $storageValue = $storageProvided ? (string)$body['storage'] : null;
        unset($body['storage']);

        $factory->update($entity, $body);

        // Storage semantics: omitted = leave as-is; "" = clear; any other value = upsert.
        if ($storageProvided) {
            if ($storageValue === '') {
                $this->clearStorage((int)$entity->entityId);
            } else {
                DatabaseAdapter::setStorage($entity, $storageValue);
            }
        }

        $allowedBrothers = $options['brothers'] ?? [];
        if (!empty($brothersData) && !empty($allowedBrothers)) {
            foreach ($brothersData as $verb => $entries) {
                if (in_array($verb, $allowedBrothers, true)) {
                    foreach ($entries as $entry) {
                        $target = $entry['target'] ?? null;
                        $refs = $entry['refs'] ?? [];
                        if ($target !== null) {
                            $entity->setBrotherEntity($verb, $target, $refs);
                        }
                    }
                }
            }
        }

        $linkedJoined = [];
        $allowedJoined = $options['joined'] ?? [];
        if (!empty($joinedData) && !empty($allowedJoined)) {
            foreach ($joinedData as $verb => $ids) {
                if (isset($allowedJoined[$verb])) {
                    $joinedFactory = $allowedJoined[$verb];
                    if (!$joinedFactory->isPopulated()) {
                        $joinedFactory->populateLocal();
                    }
                    foreach ($ids as $conceptId) {
                        $targetEntity = $this->findEntityById($joinedFactory, (int)$conceptId);
                        if ($targetEntity !== null) {
                            $entity->setJoinedEntity($verb, $targetEntity, []);
                            $linkedJoined[$verb][] = $targetEntity;
                        }
                    }
                }
            }
        }

        $result = $this->serializeEntity($entity, $options, $storageProvided);
        if (!empty($linkedJoined)) {
            $joined = $result['joined'] ?? [];
            foreach ($this->serializeLinkedJoined($linkedJoined, $options) as $verb => $entries) {
                $joined[$verb] = array_merge($joined[$verb] ?? [], $entries);
            }
            $result['joined'] = $joined;
        }

        return new ApiResponse(200, $result);
    }

    private function handleDelete(EntityFactory $factory, array $options, ?string $id): ApiResponse
    {
        if (!$options['delete']) {
            return new ApiResponse(405, [], 'Delete not allowed on this resource');
        }

        if ($id === null) {
            return new ApiResponse(422, [], 'Entity ID is required for delete');
        }

        if (!$factory->isPopulated()) {
            $factory->populateLocal();
        }

        $entity = $this->findEntityById($factory, (int)$id);
        if ($entity === null) {
            return new ApiResponse(404, [], "Entity with id $id not found");
        }

        $entity->delete();

        return new ApiResponse(200, ['deleted' => true, 'id' => (int)$id]);
    }

    private function handleSearch(EntityFactory $factory, array $options, string $searchQuery, array $queryParams, bool $includeStorage = false): ApiResponse
    {
        $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;

        $searcher = new BasicSearch();
        $results = [];

        // Search in each searchable field and merge results
        foreach ($options['searchable'] as $field) {
            $fieldResults = $searcher->searchByField($factory, $field, $searchQuery, $limit);
            foreach ($fieldResults as $entity) {
                $key = $entity->subjectConcept->idConcept;
                $results[$key] = $entity;
            }
        }

        $resultList = array_values($results);
        $storageByLinkId = $includeStorage ? $this->batchFetchStorage($resultList) : [];

        $items = array_map(
            fn(Entity $e) => $this->serializeEntity($e, $options, $includeStorage, $storageByLinkId),
            $resultList
        );
        $items = array_slice($items, 0, $limit);

        return new ApiResponse(200, [
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * @param array<int,string|null> $storageByLinkId Optional precomputed storage map (link id → value).
     */
    private function serializeEntity(Entity $entity, array $options = [], bool $includeStorage = false, array $storageByLinkId = []): array
    {
        $refs = [];
        if (is_array($entity->entityRefs)) {
            foreach ($entity->entityRefs as $ref) {
                if ($ref instanceof \SandraCore\Reference) {
                    $name = $ref->refConcept->getDisplayName();
                    if ($name !== null && $name !== 'creationTimestamp') {
                        $refs[$name] = $ref->refValue;
                    }
                }
            }
        }

        $conceptId = (int)$entity->subjectConcept->idConcept;
        $linkId = (int)$entity->entityId;
        $result = [
            'id' => $conceptId,
            'refs' => $refs,
        ];

        if ($includeStorage) {
            $result['storage'] = array_key_exists($linkId, $storageByLinkId)
                ? $storageByLinkId[$linkId]
                : DatabaseAdapter::rawGetStorage($linkId, $this->system);
        }

        $brotherVerbs = $options['brothers'] ?? [];
        if (!empty($brotherVerbs)) {
            $brothers = [];
            foreach ($brotherVerbs as $verb) {
                $brotherEntities = $entity->getBrotherEntitiesOnVerb($verb);
                $entries = [];
                foreach ($brotherEntities as $brotherEntity) {
                    $brotherRefs = [];
                    if (is_array($brotherEntity->entityRefs)) {
                        foreach ($brotherEntity->entityRefs as $ref) {
                            if ($ref instanceof \SandraCore\Reference) {
                                $name = $ref->refConcept->getDisplayName();
                                if ($name !== null && $name !== 'creationTimestamp') {
                                    $brotherRefs[$name] = $ref->refValue;
                                }
                            }
                        }
                    }
                    $entries[] = [
                        'target' => $brotherEntity->targetConcept->getDisplayName(),
                        'targetConceptId' => (int)$brotherEntity->targetConcept->idConcept,
                        'refs' => $brotherRefs,
                    ];
                }
                $brothers[$verb] = $entries;
            }
            $result['brothers'] = $brothers;
        }

        $joinedVerbs = $options['joined'] ?? [];
        if (!empty($joinedVerbs)) {
            $joined = [];
            foreach ($joinedVerbs as $verb => $joinedFactory) {
                $joinedEntities = $entity->getJoinedEntities($verb);
                $entries = [];
                if (!empty($joinedEntities)) {
                    foreach ($joinedEntities as $joinedEntity) {
                        $joinedRefs = [];
                        if (is_array($joinedEntity->entityRefs)) {
                            foreach ($joinedEntity->entityRefs as $ref) {
                                if ($ref instanceof \SandraCore\Reference) {
                                    $name = $ref->refConcept->getDisplayName();
                                    if ($name !== null && $name !== 'creationTimestamp') {
                                        $joinedRefs[$name] = $ref->refValue;
                                    }
                                }
                            }
                        }
                        $entries[] = [
                            'id' => (int)$joinedEntity->subjectConcept->idConcept,
                            'refs' => $joinedRefs,
                        ];
                    }
                }
                $joined[$verb] = $entries;
            }
            $result['joined'] = $joined;
        }

        return $result;
    }

    private function serializeLinkedJoined(array $linkedJoined, array $options): array
    {
        $joined = [];
        foreach ($linkedJoined as $verb => $entities) {
            $entries = [];
            foreach ($entities as $joinedEntity) {
                $joinedRefs = [];
                if (is_array($joinedEntity->entityRefs)) {
                    foreach ($joinedEntity->entityRefs as $ref) {
                        if ($ref instanceof \SandraCore\Reference) {
                            $name = $ref->refConcept->getDisplayName();
                            if ($name !== null && $name !== 'creationTimestamp') {
                                $joinedRefs[$name] = $ref->refValue;
                            }
                        }
                    }
                }
                $entries[] = [
                    'id' => (int)$joinedEntity->subjectConcept->idConcept,
                    'refs' => $joinedRefs,
                ];
            }
            $joined[$verb] = $entries;
        }
        return $joined;
    }

    private function findEntityById(EntityFactory $factory, int $conceptId): ?Entity
    {
        foreach ($factory->getEntities() as $entity) {
            if ((int)$entity->subjectConcept->idConcept === $conceptId) {
                return $entity;
            }
        }
        return null;
    }

    /**
     * Permissive boolean coercion for query-string flags (`?include_storage=true`,
     * `1`, `yes`, `on`). Anything else (including absent) is false.
     */
    private function parseBoolQuery(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Fetch the long-text storage rows for a batch of entities in a single
     * SQL query, keyed by link id. Keeps GET-list responses to a fixed
     * number of round-trips regardless of page size.
     *
     * @param Entity[] $entities
     * @return array<int,string|null>
     */
    private function batchFetchStorage(array $entities): array
    {
        if (empty($entities)) {
            return [];
        }

        $ids = [];
        foreach ($entities as $entity) {
            // Storage rows key on the entity's link id (entityId — the row id of
            // the underlying `<entity> is_a <factory>` triplet), not on the
            // entity's concept id. setStorage/rawSetStorage use the same key.
            $ids[] = (int)$entity->entityId;
        }
        $ids = array_values(array_unique($ids));

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = $this->system->getConnection();
        $tableStorage = $this->system->tableStorage;
        $sql = "SELECT linkReferenced, `value` FROM $tableStorage WHERE linkReferenced IN ($placeholders)";

        $params = [];
        foreach ($ids as $i => $id) {
            $params[$i + 1] = [$id, PDO::PARAM_INT];
        }

        $rows = QueryExecutor::fetchAll($pdo, $sql, $params);
        $map = [];
        if ($rows) {
            foreach ($rows as $row) {
                $map[(int)$row['linkReferenced']] = $row['value'];
            }
        }
        return $map;
    }

    /**
     * Delete the storage row for an entity (PUT with empty string), keeping
     * the "no row = no payload" invariant — consistent with sandra_update_triplet.
     */
    private function clearStorage(int $linkId): void
    {
        $pdo = $this->system->getConnection();
        $tableStorage = $this->system->tableStorage;
        QueryExecutor::execute(
            $pdo,
            "DELETE FROM $tableStorage WHERE linkReferenced = :linkId",
            [':linkId' => [$linkId, PDO::PARAM_INT]]
        );
    }
}
