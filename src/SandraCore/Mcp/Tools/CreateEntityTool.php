<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;

class CreateEntityTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_create_entity';
    }

    public function description(): string
    {
        return 'Create a new entity in a factory with the given reference values.';
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
                'refs' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of reference data (e.g. {"name": "Fido", "breed": "Lab"})',
                ],
            ],
            'required' => ['factory', 'refs'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $refs = $args['refs'] ?? [];

        $entity = $factory->createNew($refs);

        return EntitySerializer::serialize($entity);
    }
}
