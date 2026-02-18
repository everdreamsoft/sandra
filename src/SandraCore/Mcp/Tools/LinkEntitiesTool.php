<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class LinkEntitiesTool implements McpToolInterface
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
        return 'sandra_link_entities';
    }

    public function description(): string
    {
        return 'Link a source entity to a target via a brother verb relationship. Does NOT load the entire factory into memory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name containing the source entity',
                ],
                'sourceId' => [
                    'type' => 'integer',
                    'description' => 'Concept ID of the source entity',
                ],
                'verb' => [
                    'type' => 'string',
                    'description' => 'The verb (relationship type) for the link',
                ],
                'target' => [
                    'description' => 'Target concept name (string) or concept ID (integer)',
                ],
                'refs' => [
                    'type' => 'object',
                    'description' => 'Optional references to attach to the brother link',
                ],
            ],
            'required' => ['factory', 'sourceId', 'verb', 'target'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $sourceId = (int)($args['sourceId'] ?? 0);
        $verb = $args['verb'] ?? '';
        $target = $args['target'] ?? '';
        $refs = $args['refs'] ?? [];

        // Load only the source entity via a fresh factory with pre-set conceptArray
        $singleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $singleFactory->conceptArray = [$sourceId];
        $singleFactory->populateLocal();

        $entity = null;
        foreach ($singleFactory->getEntities() as $e) {
            if ((int)$e->subjectConcept->idConcept === $sourceId) {
                $entity = $e;
                break;
            }
        }

        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $sourceId not found in factory '$name'");
        }

        $entity->setBrotherEntity($verb, $target, $refs);

        return [
            'linked' => true,
            'source' => $sourceId,
            'verb' => $verb,
            'target' => $target,
        ];
    }
}
