<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class UpdateEntityTool implements McpToolInterface
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
        return 'sandra_update_entity';
    }

    public function description(): string
    {
        return 'Update an existing entity\'s reference values. Does NOT load the entire factory into memory.';
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
                'id' => [
                    'type' => 'integer',
                    'description' => 'The concept ID of the entity to update',
                ],
                'refs' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of reference data to update',
                ],
            ],
            'required' => ['factory', 'id', 'refs'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $id = (int)($args['id'] ?? 0);
        $refs = $args['refs'] ?? [];

        // Load only this single entity via a fresh factory with pre-set conceptArray
        $singleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $singleFactory->conceptArray = [$id];
        $singleFactory->populateLocal();

        $entity = null;
        foreach ($singleFactory->getEntities() as $e) {
            if ((int)$e->subjectConcept->idConcept === $id) {
                $entity = $e;
                break;
            }
        }

        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $id not found in factory '$name'");
        }

        $singleFactory->update($entity, $refs);

        return EntitySerializer::serialize($entity);
    }
}
