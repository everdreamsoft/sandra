<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;

class GetEntityTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_get_entity';
    }

    public function description(): string
    {
        return 'Get a single entity by its concept ID from a factory, including refs, brothers, and joined entities.';
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
                    'description' => 'The concept ID of the entity',
                ],
            ],
            'required' => ['factory', 'id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $entry = $this->factories[$name];
        $factory = $entry['factory'];
        $options = $entry['options'];
        $id = (int)($args['id'] ?? 0);

        if (!$factory->isPopulated()) {
            $factory->populateLocal();
        }

        $entity = $this->findEntityById($factory, $id);
        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $id not found in factory '$name'");
        }

        return EntitySerializer::serialize($entity, $options);
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
