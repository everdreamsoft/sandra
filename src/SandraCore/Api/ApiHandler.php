<?php
declare(strict_types=1);

namespace SandraCore\Api;

use SandraCore\DatabaseAdapter;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use PDO;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\QueryExecutor;
use SandraCore\Search\BasicSearch;
use SandraCore\System;
use SandraCore\Validation\ValidationException;

class ApiHandler
{
    private System $system;
    private ?EmbeddingService $embeddingService;

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

    /**
     * @param EmbeddingService|null $embeddingService Optional — when provided
     *   (and available), entities created or updated through this handler are
     *   automatically indexed in the semantic search store, mirroring the
     *   MCP create/update path.
     */
    public function __construct(System $system, ?EmbeddingService $embeddingService = null)
    {
        $this->system = $system;
        $this->embeddingService = $embeddingService;
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

        // Exact ref filters (?ref[name]=value) — open-schema: any ref name is accepted.
        // Multiple ref[…] entries combine with AND. Unknown ref names yield an empty set
        // (a filter you can't match is still a well-formed filter).
        $refFilters = $this->parseRefFilters($query['ref'] ?? null);
        $searchTerm = isset($query['search']) ? (string)$query['search'] : null;
        $hasFilter = ($refFilters !== [] || ($searchTerm !== null && $searchTerm !== ''));

        // List with pagination
        $limit = isset($query['limit']) ? (int)$query['limit'] : 50;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;

        // Filtered listing (ref[] and/or always-on search) — load all entities then
        // filter in memory. Pagination applies after filtering.
        if ($hasFilter) {
            if (!$factory->isPopulated()) {
                $factory->populateLocal();
            }
            if (!empty($options['joined'])) {
                $factory->joinPopulate();
            }
            $matched = $this->filterEntities(
                array_values($factory->getEntities()),
                $refFilters,
                $searchTerm,
                $factory,
                $options
            );
            $total = count($matched);
            $slice = array_slice($matched, $offset, $limit);

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
        $embedRequested = !empty($body['embed']);
        unset($body['embed']);

        // Open-schema joined pre-validation: when no whitelist is configured and
        // the body carries joined data, every target concept must already be an
        // entity. Reject upfront with 422 so we don't orphan a half-created row.
        $allowedJoined = $options['joined'] ?? [];
        if (empty($allowedJoined) && !empty($joinedData)) {
            $invalid = $this->collectInvalidJoinedIds($joinedData);
            if ($invalid !== []) {
                return new ApiResponse(422, ['invalidJoinedIds' => $invalid], 'One or more joined target IDs do not refer to existing entities');
            }
        }

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

        if ($embedRequested) {
            $this->maybeEmbed($entity);
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
        $openJoinedPosted = [];
        if (!empty($joinedData) && !empty($allowedJoined)) {
            // Backward-compatible whitelist path: factory was registered with a
            // joined => [verb => factory] map; only those verbs route here, and
            // the target must live in the declared factory.
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
        } elseif (!empty($joinedData)) {
            // Open-schema path: any verb, any target factory. Pre-validated above.
            [, $openJoinedPosted, ] = $this->applyOpenJoined($entity, $joinedData);
        }

        $result = $this->serializeEntity($entity, $options, $storageProvided);
        if (!empty($linkedJoined)) {
            $result['joined'] = $this->serializeLinkedJoined($linkedJoined, $options);
        }
        if (!empty($openJoinedPosted)) {
            $result['joined'] = ($result['joined'] ?? []) + $openJoinedPosted;
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
        $embedRequested = !empty($body['embed']);
        unset($body['embed']);

        // Open-schema joined pre-validation (mirrors POST).
        $allowedJoined = $options['joined'] ?? [];
        if (empty($allowedJoined) && !empty($joinedData)) {
            $invalid = $this->collectInvalidJoinedIds($joinedData);
            if ($invalid !== []) {
                return new ApiResponse(422, ['invalidJoinedIds' => $invalid], 'One or more joined target IDs do not refer to existing entities');
            }
        }

        $factory->update($entity, $body);

        // Storage semantics: omitted = leave as-is; "" = clear; any other value = upsert.
        if ($storageProvided) {
            if ($storageValue === '') {
                $this->clearStorage((int)$entity->entityId);
            } else {
                DatabaseAdapter::setStorage($entity, $storageValue);
            }
        }

        if ($embedRequested) {
            $this->maybeEmbed($entity);
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
        $openJoinedPosted = [];
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
        } elseif (!empty($joinedData)) {
            [, $openJoinedPosted, ] = $this->applyOpenJoined($entity, $joinedData);
        }

        $result = $this->serializeEntity($entity, $options, $storageProvided);
        if (!empty($linkedJoined)) {
            $joined = $result['joined'] ?? [];
            foreach ($this->serializeLinkedJoined($linkedJoined, $options) as $verb => $entries) {
                $joined[$verb] = array_merge($joined[$verb] ?? [], $entries);
            }
            $result['joined'] = $joined;
        }
        if (!empty($openJoinedPosted)) {
            $joined = $result['joined'] ?? [];
            foreach ($openJoinedPosted as $verb => $ids) {
                $joined[$verb] = array_merge($joined[$verb] ?? [], $ids);
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
     * Normalize the `ref[…]` query parameter into a `[refName => stringValue]` map.
     * Accepts array form (`?ref[name]=foo&ref[type]=bar`) — anything else is dropped
     * silently. Values are coerced to string; non-scalar values are skipped.
     *
     * @return array<string,string>
     */
    private function parseRefFilters(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $filters = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $filters[$key] = (string)$value;
        }
        return $filters;
    }

    /**
     * Apply ref-exact filters + optional fuzzy search to a populated entity set.
     * Open-schema: any ref name is accepted on ref[]; missing names produce no match.
     * Search uses the factory's `searchable` whitelist when configured; otherwise
     * falls back to every string ref the matched entity carries.
     *
     * Filtering is in-memory after populateLocal. Acceptable for factories of
     * reasonable size; very large factories should consider pushing filters
     * to SQL — flagged as a known limit in the REST API doc.
     *
     * @param Entity[] $entities
     * @param array<string,string> $refFilters
     * @return Entity[]
     */
    private function filterEntities(array $entities, array $refFilters, ?string $searchTerm, EntityFactory $factory, array $options): array
    {
        // Phase 1 — exact ref equality (AND).
        if ($refFilters !== []) {
            $entities = array_values(array_filter(
                $entities,
                fn(Entity $e) => $this->entityMatchesRefFilters($e, $refFilters)
            ));
        }

        // Phase 2 — fuzzy search (whitelisted if configured, open otherwise).
        if ($searchTerm === null || $searchTerm === '') {
            return $entities;
        }
        $needle = mb_strtolower($searchTerm);
        $whitelist = $options['searchable'] ?? [];
        return array_values(array_filter(
            $entities,
            fn(Entity $e) => $this->entityMatchesSearch($e, $needle, $whitelist)
        ));
    }

    /**
     * True iff every (refName => value) pair in $filters is present on the entity
     * with an exact-string match.
     *
     * @param array<string,string> $filters
     */
    private function entityMatchesRefFilters(Entity $entity, array $filters): bool
    {
        if (!is_array($entity->entityRefs)) {
            return false;
        }
        $remaining = $filters;
        foreach ($entity->entityRefs as $ref) {
            if (!($ref instanceof \SandraCore\Reference)) {
                continue;
            }
            $name = $ref->refConcept->getDisplayName();
            if ($name === null) {
                continue;
            }
            if (array_key_exists($name, $remaining) && (string)$ref->refValue === $remaining[$name]) {
                unset($remaining[$name]);
                if ($remaining === []) {
                    return true;
                }
            }
        }
        return $remaining === [];
    }

    /**
     * Case-insensitive substring search across an entity's refs. When the factory
     * has a `searchable` whitelist, only those fields are scanned; otherwise any
     * string ref counts (open-schema fallback so ?search= never silently no-ops).
     *
     * @param string[] $whitelist
     */
    private function entityMatchesSearch(Entity $entity, string $needle, array $whitelist): bool
    {
        if (!is_array($entity->entityRefs)) {
            return false;
        }
        $restrict = !empty($whitelist);
        foreach ($entity->entityRefs as $ref) {
            if (!($ref instanceof \SandraCore\Reference)) {
                continue;
            }
            $name = $ref->refConcept->getDisplayName();
            if ($name === null || $name === 'creationTimestamp') {
                continue;
            }
            if ($restrict && !in_array($name, $whitelist, true)) {
                continue;
            }
            $value = $ref->refValue;
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            if (mb_stripos((string)$value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scan a joined payload (verb => [ids…]) and return every id that does
     * not refer to an existing entity. Used to pre-validate before any write
     * so we don't half-create / half-link.
     *
     * @return array<int|string>
     */
    private function collectInvalidJoinedIds(array $joinedData): array
    {
        $invalid = [];
        foreach ($joinedData as $verb => $ids) {
            if (!is_string($verb) || $verb === '' || !is_array($ids)) {
                continue;
            }
            foreach ($ids as $rawId) {
                if (!is_numeric($rawId)) {
                    $invalid[] = $rawId;
                    continue;
                }
                $cid = (int)$rawId;
                if (!$this->conceptIsEntity($cid)) {
                    $invalid[] = $cid;
                }
            }
        }
        return $invalid;
    }

    /**
     * Verify a concept id refers to an existing entity (any factory) by
     * looking up at least one outgoing `is_a` triplet. Returns true when found.
     */
    private function conceptIsEntity(int $conceptId): bool
    {
        $isaId = (int)$this->system->systemConcept->get('is_a');
        if ($isaId <= 0) {
            return false;
        }
        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $sql = "SELECT 1 FROM `{$linkTable}` WHERE idConceptStart = :id AND idConceptLink = :isaId LIMIT 1";
        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':id' => [$conceptId, PDO::PARAM_INT],
            ':isaId' => [$isaId, PDO::PARAM_INT],
        ]);
        return !empty($rows);
    }

    /**
     * Open-schema joined linking: accept any verb in body.joined and create
     * `entity --verb--> targetConceptId` triplets after validating each target
     * concept is an existing entity (any factory).
     *
     * @param array<string,int[]> $joinedData verb → array of concept ids
     * @return array{0:bool,1:array,2:int[]} [allValid, postedTriplets, invalidIds]
     */
    private function applyOpenJoined(Entity $entity, array $joinedData): array
    {
        $invalidIds = [];
        // First pass: validate every id; bail out early if anything is missing.
        foreach ($joinedData as $verb => $ids) {
            if (!is_string($verb) || $verb === '' || !is_array($ids)) {
                continue;
            }
            foreach ($ids as $rawId) {
                if (!is_numeric($rawId)) {
                    $invalidIds[] = $rawId;
                    continue;
                }
                $cid = (int)$rawId;
                if (!$this->conceptIsEntity($cid)) {
                    $invalidIds[] = $cid;
                }
            }
        }
        if ($invalidIds !== []) {
            return [false, [], $invalidIds];
        }

        // Second pass: create triplets. Verbs are auto-created concepts (true =
        // create-if-missing), matching the rest of Sandra's open vocabulary.
        $posted = [];
        $entityConceptId = (int)$entity->subjectConcept->idConcept;
        foreach ($joinedData as $verb => $ids) {
            if (!is_string($verb) || $verb === '' || !is_array($ids)) {
                continue;
            }
            $verbConceptId = (int)$this->system->systemConcept->get($verb, null, true);
            foreach ($ids as $rawId) {
                $cid = (int)$rawId;
                DatabaseAdapter::rawCreateTriplet($entityConceptId, $verbConceptId, $cid, $this->system);
                $posted[$verb][] = $cid;
            }
        }
        return [true, $posted, []];
    }

    /**
     * Index the entity in the semantic search store. Only called when the
     * client opts in via `"embed": true` in the request body — the REST
     * surface keeps embedding strictly opt-in so high-frequency writes
     * (logs, pings, audit trails) don't silently rack up OpenAI calls.
     * No-op when no service is configured or available. Embedding failures
     * are swallowed — they must never block a write.
     */
    private function maybeEmbed(Entity $entity): void
    {
        if ($this->embeddingService === null || !$this->embeddingService->isAvailable()) {
            return;
        }
        try {
            $this->embeddingService->embedEntity($entity);
        } catch (\Throwable $e) {
            // Non-fatal: embedding failure should not block the API write.
        }
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
