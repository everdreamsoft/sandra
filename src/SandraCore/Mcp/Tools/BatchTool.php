<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\WriteGuard;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\FactoryDiscovery;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * Batch tool: create multiple concepts, entities and triplets in a single call.
 *
 * Supports forward references so that triplets can reference entities/concepts
 * created earlier in the same batch:
 *   "$concept.0" → ID of the first concept created
 *   "$entity.2"  → concept ID of the third entity created
 */
class BatchTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    /** @var array<string, array{isa: string, cif: string, options: array}> */
    private array $factoryMeta;
    private System $system;
    private ?EmbeddingService $embeddingService;

    public function __construct(array &$factories, array &$factoryMeta, System $system, ?EmbeddingService $embeddingService = null)
    {
        $this->factories = &$factories;
        $this->factoryMeta = &$factoryMeta;
        $this->system = $system;
        $this->embeddingService = $embeddingService;
    }

    public function name(): string
    {
        return 'sandra_batch';
    }

    public function description(): string
    {
        return 'Create multiple concepts, entities, and triplets in a single call. '
            . 'Use this instead of repeated single-creation calls. '
            . 'Operations run in order: concepts first, then entities, then triplets. '
            . 'Triplets can reference results from the same batch using "$concept.0" or "$entity.2" syntax '
            . '(index into the concepts/entities arrays). '
            . 'Triplets also accept an optional "refs" object ({key: value}) to attach scalar metadata '
            . '(price, date, score...) directly to the link. Ref keys are auto-created as concepts if needed.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concepts' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of concept shortnames to create (e.g. ["urgent", "important", "tag"]). Existing concepts are reused.',
                ],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'factory' => ['type' => 'string', 'description' => 'Factory name (auto-created if new)'],
                            'refs' => ['type' => 'object', 'description' => 'Key-value reference data'],
                            'storage' => ['type' => 'string', 'description' => 'Optional long text content'],
                        ],
                        'required' => ['factory', 'refs'],
                    ],
                    'description' => 'List of entities to create.',
                ],
                'triplets' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'subject' => [
                                'type' => ['string', 'integer'],
                                'description' => 'Subject: concept ID, shortname, "$concept.N", or "$entity.N"',
                            ],
                            'verb' => [
                                'type' => ['string', 'integer'],
                                'description' => 'Verb: concept ID, shortname, or "$concept.N"',
                            ],
                            'target' => [
                                'type' => ['string', 'integer'],
                                'description' => 'Target: concept ID, shortname, "$concept.N", or "$entity.N"',
                            ],
                            'refs' => [
                                'type' => 'object',
                                'description' => 'Optional key-value scalar metadata attached to the link (e.g. {"price": 350, "date": "summer 2021", "holiday": "Christmas"}). Keys become reusable concepts; values are stored as strings (truncated to 255 chars).',
                            ],
                            'storage' => [
                                'type' => 'string',
                                'description' => 'Optional long-text payload attached to the triplet (MEDIUMTEXT). Stored separately from refs.',
                            ],
                        ],
                        'required' => ['subject', 'verb', 'target'],
                    ],
                    'description' => 'List of triplets to create. Use "$concept.N" / "$entity.N" to reference items created in this batch. Use "refs" to attach scalar metadata to the link.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $conceptNames = $args['concepts'] ?? [];
        $entityDefs = $args['entities'] ?? [];
        $tripletDefs = $args['triplets'] ?? [];

        $results = [
            'concepts' => [],
            'entities' => [],
            'triplets' => [],
        ];

        // One guard for the whole batch. Every phase answers to the same rules
        // as its single-shot tool — a batch must not be a way around them.
        $guard = WriteGuard::forAccess($this->system, $this->access);

        // Pre-flight the links that reach OUTSIDE the batch (both endpoints
        // given literally). Those are the ones that could smuggle a write into
        // an ungranted file, and the batch has no transaction — refusing them
        // before phase 1 keeps a denial from leaving concepts and entities
        // behind. Links onto $concept.N / $entity.N are checked in phase 3,
        // once their ids exist; their files were authorised on creation.
        if ($guard !== null) {
            foreach ($tripletDefs as $def) {
                $subject = $def['subject'] ?? '';
                $target = $def['target'] ?? '';
                if ($this->isBatchRef($subject) || $this->isBatchRef($target)) {
                    continue;
                }
                $guard->assertCanLink(
                    $this->resolveRef($subject, [], []),
                    $this->resolveRef($def['verb'] ?? '', [], []),
                    $this->resolveRef($target, [], [])
                );
            }
        }

        // --- Phase 1: Create concepts ---
        $conceptIds = [];
        foreach ($conceptNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $existingId = $this->system->systemConcept->get($name, null, false);
            $created = ($existingId === null);
            if ($created) {
                $guard?->assertCanMintConcept();
            }
            $id = $created
                ? (int)$this->system->systemConcept->get($name, null, true)
                : (int)$existingId;
            $conceptIds[] = $id;
            $results['concepts'][] = [
                'id' => $id,
                'shortname' => $name,
                'created' => $created,
            ];
        }

        // --- Phase 2: Create entities ---
        $entityConceptIds = [];
        foreach ($entityDefs as $def) {
            $isa = (string) ($def['factory'] ?? '');
            $factoryName = $this->keyFor($isa, $def['contained_in_file'] ?? null);
            $refs = $def['refs'] ?? [];

            if (!isset($this->factories[$factoryName])) {
                $cif = $def['contained_in_file'] ?? $isa . '_file';
                $guard?->assertCanCreateFactory($cif);
                $options = ['brothers' => [], 'joined' => []];
                // Registry key may be qualified; the is_a never is.
                $factory = new EntityFactory($isa, $cif, $this->system);
                $this->factories[$factoryName] = [
                    'factory' => $factory,
                    'options' => $options,
                ];
                $this->factoryMeta[$factoryName] = [
                    'isa' => $isa,
                    'cif' => $cif,
                    'options' => $options,
                ];
            }

            $factory = $this->factories[$factoryName]['factory'];
            $guard?->assertCanCreateInFile((string)$factory->entityContainedIn);
            $entity = $factory->createNew($refs);

            $storage = $def['storage'] ?? null;
            if ($storage !== null) {
                $entity->setStorage($storage);
            }

            if ($this->embeddingService !== null && $this->embeddingService->isAvailable()) {
                try {
                    $this->embeddingService->embedEntity($entity);
                } catch (\Throwable $e) {
                    // Non-fatal: embedding failure should not block batch entity creation
                }
            }

            $entityConceptIds[] = (int)$entity->subjectConcept->idConcept;
            $serializeOptions = $storage !== null ? ['include_storage' => true] : [];
            $serialized = EntitySerializer::serialize($entity, $serializeOptions);
            $serialized['factory'] = $factoryName;
            $results['entities'][] = $serialized;
        }

        // --- Phase 3: Create triplets ---
        foreach ($tripletDefs as $def) {
            $subjectId = $this->resolveRef($def['subject'] ?? '', $conceptIds, $entityConceptIds);
            $verbId = $this->resolveRef($def['verb'] ?? '', $conceptIds, $entityConceptIds);
            $targetId = $this->resolveRef($def['target'] ?? '', $conceptIds, $entityConceptIds);

            $linkId = DatabaseAdapter::rawCreateTriplet($subjectId, $verbId, $targetId, $this->system, 0, true, $guard);
            if ($linkId === null) {
                $results['triplets'][] = [
                    'error' => "Failed to create triplet ($subjectId → $verbId → $targetId)",
                ];
                continue;
            }

            $refs = $def['refs'] ?? [];
            $refsAttached = 0;
            foreach ($refs as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $keyConceptId = (int)$this->system->systemConcept->get((string)$key, null, true);
                $refResult = DatabaseAdapter::rawCreateReference($linkId, $keyConceptId, (string)$value, $this->system, true);
                if ($refResult !== null) {
                    $refsAttached++;
                }
            }

            $tripletResult = [
                'linkId' => (int)$linkId,
                'subjectId' => $subjectId,
                'verbId' => $verbId,
                'targetId' => $targetId,
            ];
            if ($refsAttached > 0) {
                $tripletResult['refsAttached'] = $refsAttached;
            }

            $storage = $def['storage'] ?? null;
            if ($storage !== null && $storage !== '') {
                DatabaseAdapter::rawSetStorage((int)$linkId, (string)$storage, $this->system);
                $tripletResult['storageSet'] = true;
            }

            $results['triplets'][] = $tripletResult;
        }

        $results['summary'] = [
            'conceptsCreated' => count(array_filter($results['concepts'], fn($c) => $c['created'])),
            'conceptsReused' => count(array_filter($results['concepts'], fn($c) => !$c['created'])),
            'entitiesCreated' => count($results['entities']),
            'tripletsCreated' => count(array_filter($results['triplets'], fn($t) => isset($t['linkId']))),
            'refsAttached' => array_sum(array_map(fn($t) => $t['refsAttached'] ?? 0, $results['triplets'])),
            'errors' => count(array_filter($results['triplets'], fn($t) => isset($t['error']))),
        ];

        return $results;
    }

    /**
     * Resolve a value to a concept ID.
     * Supports: numeric ID, "$concept.N", "$entity.N", or shortname lookup.
     */
/**
     * Registry key for (is_a, requested file). Falls back to the bare is_a when
     * no file is named, or when the registered factory already sits in it.
     */
    private function keyFor(string $isa, ?string $cif): string
    {
        if ($cif === null || !isset($this->factories[$isa])) {
            return $isa;
        }
        if ((string) $this->factories[$isa]['factory']->entityContainedIn === $cif) {
            return $isa;
        }

        return FactoryDiscovery::qualifiedName($isa, $cif);
    }

    /** True for the "$concept.N" / "$entity.N" placeholders resolved mid-batch. */
    private function isBatchRef(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '$');
    }

    private function resolveRef(mixed $value, array $conceptIds, array $entityConceptIds): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value)) {
            // Batch reference: $concept.N
            if (preg_match('/^\$concept\.(\d+)$/', $value, $m)) {
                $idx = (int)$m[1];
                if (!isset($conceptIds[$idx])) {
                    throw new \InvalidArgumentException("Batch reference $value: concept index $idx does not exist (only " . count($conceptIds) . " concepts created)");
                }
                return $conceptIds[$idx];
            }

            // Batch reference: $entity.N
            if (preg_match('/^\$entity\.(\d+)$/', $value, $m)) {
                $idx = (int)$m[1];
                if (!isset($entityConceptIds[$idx])) {
                    throw new \InvalidArgumentException("Batch reference $value: entity index $idx does not exist (only " . count($entityConceptIds) . " entities created)");
                }
                return $entityConceptIds[$idx];
            }

            // Shortname lookup
            if ($value !== '') {
                $id = $this->system->systemConcept->get($value, null, false);
                if ($id !== null) {
                    return (int)$id;
                }
                throw new \InvalidArgumentException("Concept not found: '$value'. Include it in the concepts array or use sandra_create_concept first.");
            }
        }

        throw new \InvalidArgumentException('Invalid reference: must be an ID, shortname, "$concept.N", or "$entity.N"');
    }
}
