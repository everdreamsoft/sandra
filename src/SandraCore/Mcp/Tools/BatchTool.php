<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
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
class BatchTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    /** @var array<string, array{isa: string, cif: string, options: array}> */
    private array $factoryMeta;
    private System $system;

    public function __construct(array &$factories, array &$factoryMeta, System $system)
    {
        $this->factories = &$factories;
        $this->factoryMeta = &$factoryMeta;
        $this->system = $system;
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
            . '(index into the concepts/entities arrays).';
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
                        ],
                        'required' => ['subject', 'verb', 'target'],
                    ],
                    'description' => 'List of triplets to create. Use "$concept.N" / "$entity.N" to reference items created in this batch.',
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

        // --- Phase 1: Create concepts ---
        $conceptIds = [];
        foreach ($conceptNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $existingId = $this->system->systemConcept->get($name, null, false);
            $created = ($existingId === null);
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
            $factoryName = $def['factory'] ?? '';
            $refs = $def['refs'] ?? [];

            if (!isset($this->factories[$factoryName])) {
                $cif = $def['contained_in_file'] ?? $factoryName . '_file';
                $options = ['brothers' => [], 'joined' => []];
                $factory = new EntityFactory($factoryName, $cif, $this->system);
                $this->factories[$factoryName] = [
                    'factory' => $factory,
                    'options' => $options,
                ];
                $this->factoryMeta[$factoryName] = [
                    'isa' => $factoryName,
                    'cif' => $cif,
                    'options' => $options,
                ];
            }

            $factory = $this->factories[$factoryName]['factory'];
            $entity = $factory->createNew($refs);

            $storage = $def['storage'] ?? null;
            if ($storage !== null) {
                $entity->setStorage($storage);
            }

            $entityConceptIds[] = (int)$entity->subjectConcept;
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

            $linkId = DatabaseAdapter::rawCreateTriplet($subjectId, $verbId, $targetId, $this->system);
            if ($linkId === null) {
                $results['triplets'][] = [
                    'error' => "Failed to create triplet ($subjectId → $verbId → $targetId)",
                ];
                continue;
            }

            $results['triplets'][] = [
                'linkId' => (int)$linkId,
                'subjectId' => $subjectId,
                'verbId' => $verbId,
                'targetId' => $targetId,
            ];
        }

        $results['summary'] = [
            'conceptsCreated' => count(array_filter($results['concepts'], fn($c) => $c['created'])),
            'conceptsReused' => count(array_filter($results['concepts'], fn($c) => !$c['created'])),
            'entitiesCreated' => count($results['entities']),
            'tripletsCreated' => count(array_filter($results['triplets'], fn($t) => isset($t['linkId']))),
            'errors' => count(array_filter($results['triplets'], fn($t) => isset($t['error']))),
        ];

        return $results;
    }

    /**
     * Resolve a value to a concept ID.
     * Supports: numeric ID, "$concept.N", "$entity.N", or shortname lookup.
     */
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
