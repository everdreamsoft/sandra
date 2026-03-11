<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class GetSchemaTool implements McpToolInterface
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
        return 'sandra_get_schema';
    }

    public function description(): string
    {
        return 'Get the complete schema of all factories (or a specific one) including reference fields discovered from actual data, entity counts, and sample values. Use this FIRST to understand the data structure before querying.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'Optional: specific factory name. If omitted, returns schema for ALL factories.',
                ],
                'include_samples' => [
                    'type' => 'boolean',
                    'description' => 'Include sample values for each field (default true). Set false for a lighter response.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $targetFactory = $args['factory'] ?? null;
        $includeSamples = $args['include_samples'] ?? true;

        if ($targetFactory !== null) {
            if (!isset($this->factories[$targetFactory])) {
                throw new \InvalidArgumentException("Unknown factory: $targetFactory. Use sandra_list_factories to see available factories.");
            }
            return [
                'factories' => [
                    $targetFactory => $this->describeFactory($targetFactory, $this->factories[$targetFactory]['factory'], $includeSamples),
                ],
            ];
        }

        $schemas = [];
        foreach ($this->factories as $name => $entry) {
            $schemas[$name] = $this->describeFactory($name, $entry['factory'], $includeSamples);
        }

        return ['factories' => $schemas];
    }

    private function describeFactory(string $name, EntityFactory $factory, bool $includeSamples): array
    {
        $count = $factory->countEntitiesOnRequest();

        // Load a small sample to discover actual fields from data
        $sampleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $sampleFactory->populateLocal(5, 0, 'DESC');
        $sampleEntities = $sampleFactory->getEntities();

        // Collect all field names and sample values from the sample entities
        $fieldInfo = [];
        foreach ($sampleEntities as $entity) {
            $refs = EntitySerializer::extractRefs($entity);
            foreach ($refs as $fieldName => $value) {
                if (!isset($fieldInfo[$fieldName])) {
                    $fieldInfo[$fieldName] = [
                        'name' => $fieldName,
                        'samples' => [],
                    ];
                }
                if ($includeSamples && count($fieldInfo[$fieldName]['samples']) < 3 && $value !== null && $value !== '') {
                    $sampleValue = is_string($value) && strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
                    if (!in_array($sampleValue, $fieldInfo[$fieldName]['samples'], true)) {
                        $fieldInfo[$fieldName]['samples'][] = $sampleValue;
                    }
                }
            }
        }

        // Also check getReferenceMap for any declared fields not in sample
        $refMap = $factory->getReferenceMap();
        if (is_array($refMap)) {
            foreach ($refMap as $concept) {
                $displayName = $concept->getDisplayName();
                if ($displayName !== null && $displayName !== 'creationTimestamp' && !isset($fieldInfo[$displayName])) {
                    $fieldInfo[$displayName] = [
                        'name' => $displayName,
                        'samples' => [],
                    ];
                }
            }
        }

        // Check if any sample entity has data storage
        $hasStorage = false;
        $storageSample = null;
        foreach ($sampleEntities as $entity) {
            $storage = $entity->getStorage();
            if ($storage !== null && $storage !== '') {
                $hasStorage = true;
                if ($includeSamples) {
                    $storageSample = is_string($storage) && strlen($storage) > 100 ? substr($storage, 0, 100) . '...' : $storage;
                }
                break;
            }
        }

        $result = [
            'entityIsa' => $factory->entityIsa,
            'entityContainedIn' => $factory->entityContainedIn,
            'entityCount' => $count,
            'fields' => array_values($fieldInfo),
            'hasStorage' => $hasStorage,
        ];

        if ($hasStorage && $storageSample !== null) {
            $result['storageSample'] = $storageSample;
        }

        if (!$includeSamples) {
            $result['fields'] = array_map(fn($f) => ['name' => $f['name']], $result['fields']);
        }

        return $result;
    }
}
