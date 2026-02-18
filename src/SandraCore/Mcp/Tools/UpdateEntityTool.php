<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;

class UpdateEntityTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_update_entity';
    }

    public function description(): string
    {
        return 'Update an existing entity\'s reference values.';
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

        if (!$factory->isPopulated()) {
            $factory->populateLocal();
        }

        $entity = $this->findEntityById($factory, $id);
        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $id not found in factory '$name'");
        }

        $factory->update($entity, $refs);

        return EntitySerializer::serialize($entity);
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
