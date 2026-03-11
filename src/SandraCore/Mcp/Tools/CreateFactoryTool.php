<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateFactoryTool implements McpToolInterface
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
        return 'sandra_create_factory';
    }

    public function description(): string
    {
        return 'Create a new entity factory (type). Once created, you can add entities to it with sandra_create_entity. If the factory already exists, returns its current info.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Factory name (will be used as is_a type, e.g. "person", "article", "product")',
                ],
                'contained_in_file' => [
                    'type' => 'string',
                    'description' => 'Optional container file name. Defaults to "<name>_file".',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['name'] ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException('Factory name cannot be empty');
        }

        if (isset($this->factories[$name])) {
            $factory = $this->factories[$name]['factory'];
            return [
                'name' => $name,
                'entityIsa' => $factory->entityIsa,
                'entityContainedIn' => $factory->entityContainedIn,
                'created' => false,
                'message' => 'Factory already exists',
            ];
        }

        $cif = $args['contained_in_file'] ?? $name . '_file';
        $options = ['brothers' => [], 'joined' => []];
        $factory = new EntityFactory($name, $cif, $this->system);

        $this->factories[$name] = [
            'factory' => $factory,
            'options' => $options,
        ];
        $this->factoryMeta[$name] = [
            'isa' => $name,
            'cif' => $cif,
            'options' => $options,
        ];

        return [
            'name' => $name,
            'entityIsa' => $name,
            'entityContainedIn' => $cif,
            'created' => true,
        ];
    }
}
