<?php
declare(strict_types=1);

namespace SandraCore\Api;

use SandraCore\Entity;
use SandraCore\EntityFactory;
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
            return new ApiResponse(200, $this->serializeEntity($entity, $options));
        }

        // Search
        if (isset($query['search']) && !empty($options['searchable'])) {
            if (!$factory->isPopulated()) {
                $factory->populateLocal();
            }
            return $this->handleSearch($factory, $options, $query['search'], $query);
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

        $items = array_map(fn(Entity $e) => $this->serializeEntity($e, $options), $slice);

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

        try {
            $entity = $factory->createNew($body);
        } catch (ValidationException $e) {
            return new ApiResponse(422, ['errors' => $e->getErrors()], $e->getFirstError());
        } catch (\Exception $e) {
            return new ApiResponse(422, [], $e->getMessage());
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

        $result = $this->serializeEntity($entity, $options);
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

        $factory->update($entity, $body);

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

        $result = $this->serializeEntity($entity, $options);
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

    private function handleSearch(EntityFactory $factory, array $options, string $searchQuery, array $queryParams): ApiResponse
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

        $items = array_map(fn(Entity $e) => $this->serializeEntity($e, $options), array_values($results));
        $items = array_slice($items, 0, $limit);

        return new ApiResponse(200, [
            'items' => $items,
            'total' => count($items),
        ]);
    }

    private function serializeEntity(Entity $entity, array $options = []): array
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

        $result = [
            'id' => (int)$entity->subjectConcept->idConcept,
            'refs' => $refs,
        ];

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
}
