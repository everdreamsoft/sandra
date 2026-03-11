<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\Entity;
use SandraCore\Reference;

class EntitySerializer
{
    /**
     * Serialize an entity to an array.
     *
     * Options:
     *   'brothers' => ['verbName', ...]  — include brother entities
     *   'joined'   => ['verb' => $factory, ...] — include joined entities
     */
    public static function serialize(Entity $entity, array $options = []): array
    {
        $fields = $options['fields'] ?? null;
        $includeStorage = $options['include_storage'] ?? false;
        $refs = self::extractRefs($entity, $fields);

        $result = [
            'id' => (int)$entity->subjectConcept->idConcept,
            'refs' => $refs,
        ];

        if ($includeStorage) {
            $storage = $entity->getStorage();
            if ($storage !== null) {
                $result['storage'] = $storage;
            }
        }

        $brotherVerbs = $options['brothers'] ?? [];
        if (!empty($brotherVerbs)) {
            $brothers = [];
            foreach ($brotherVerbs as $verb) {
                $brotherEntities = $entity->getBrotherEntitiesOnVerb($verb);
                $entries = [];
                foreach ($brotherEntities as $brotherEntity) {
                    $entries[] = [
                        'target' => $brotherEntity->targetConcept->getDisplayName(),
                        'targetConceptId' => (int)$brotherEntity->targetConcept->idConcept,
                        'refs' => self::extractRefs($brotherEntity, $fields),
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
                        $entries[] = [
                            'id' => (int)$joinedEntity->subjectConcept->idConcept,
                            'refs' => self::extractRefs($joinedEntity, $fields),
                        ];
                    }
                }
                $joined[$verb] = $entries;
            }
            $result['joined'] = $joined;
        }

        return $result;
    }

    /**
     * Extract references from an entity as name => value pairs.
     *
     * @param Entity $entity
     * @param array|null $fields If provided, only return refs whose name is in this list
     */
    public static function extractRefs(Entity $entity, ?array $fields = null): array
    {
        $refs = [];
        if (is_array($entity->entityRefs)) {
            foreach ($entity->entityRefs as $ref) {
                if ($ref instanceof Reference) {
                    $name = $ref->refConcept->getDisplayName();
                    if ($name !== null && $name !== 'creationTimestamp') {
                        if ($fields !== null && !in_array($name, $fields, true)) {
                            continue;
                        }
                        $refs[$name] = $ref->refValue;
                    }
                }
            }
        }
        return $refs;
    }
}
