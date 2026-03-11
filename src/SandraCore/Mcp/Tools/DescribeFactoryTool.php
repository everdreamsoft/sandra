<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class DescribeFactoryTool implements McpToolInterface
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
        return 'sandra_describe_factory';
    }

    public function description(): string
    {
        return 'Describe a factory: its schema (reference fields), entity type, and count.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name',
                ],
            ],
            'required' => ['factory'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];

        // Get fields from referenceMap (declared schema)
        $refFields = [];
        $refMap = $factory->getReferenceMap();
        if (is_array($refMap)) {
            foreach ($refMap as $concept) {
                $displayName = $concept->getDisplayName();
                if ($displayName !== null && $displayName !== 'creationTimestamp') {
                    $refFields[$displayName] = true;
                }
            }
        }

        // Also discover fields from actual data (sample of entities)
        $sampleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $sampleFactory->populateLocal(5, 0, 'DESC');
        $sampleEntities = $sampleFactory->getEntities();

        $fieldSamples = [];
        foreach ($sampleEntities as $entity) {
            $refs = EntitySerializer::extractRefs($entity);
            foreach ($refs as $fieldName => $value) {
                $refFields[$fieldName] = true;
                if (!isset($fieldSamples[$fieldName]) && $value !== null && $value !== '') {
                    $sample = is_string($value) && strlen($value) > 80 ? substr($value, 0, 80) . '...' : $value;
                    $fieldSamples[$fieldName] = $sample;
                }
            }
        }

        $count = $factory->countEntitiesOnRequest();

        $fieldsWithSamples = [];
        foreach (array_keys($refFields) as $fieldName) {
            $entry = ['name' => $fieldName];
            if (isset($fieldSamples[$fieldName])) {
                $entry['example'] = $fieldSamples[$fieldName];
            }
            $fieldsWithSamples[] = $entry;
        }

        return [
            'name' => $name,
            'entityIsa' => $factory->entityIsa,
            'entityContainedIn' => $factory->entityContainedIn,
            'referenceFields' => $fieldsWithSamples,
            'count' => $count,
        ];
    }
}
