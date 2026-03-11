<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateEntityTool implements McpToolInterface
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
                    'description' => 'The factory name (is_a). If it does not exist yet, it will be created automatically.',
                ],
                'contained_in_file' => [
                    'type' => 'string',
                    'description' => 'Optional contained_in_file name. Defaults to "<factory>_file" if omitted.',
                ],
                'refs' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of reference data (e.g. {"name": "Fido", "breed": "Lab"})',
                ],
                'storage' => [
                    'type' => 'string',
                    'description' => 'Optional: long text content to store (e.g. article body, description, HTML). Stored separately from refs.',
                ],
            ],
            'required' => ['factory', 'refs'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        $refs = $args['refs'] ?? [];

        if (!isset($this->factories[$name])) {
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
        }

        $factory = $this->factories[$name]['factory'];
        $entity = $factory->createNew($refs);

        $storage = $args['storage'] ?? null;
        if ($storage !== null) {
            $entity->setStorage($storage);
        }

        $serializeOptions = $storage !== null ? ['include_storage' => true] : [];
        return EntitySerializer::serialize($entity, $serializeOptions);
    }
}
