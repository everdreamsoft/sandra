<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;

class LinkEntitiesTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_link_entities';
    }

    public function description(): string
    {
        return 'Link a source entity to a target via a brother verb relationship.';
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

        if (!$factory->isPopulated()) {
            $factory->populateLocal();
        }

        $entity = $this->findEntityById($factory, $sourceId);
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
