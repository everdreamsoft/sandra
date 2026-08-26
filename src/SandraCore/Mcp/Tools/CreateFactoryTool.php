<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\WriteGuard;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\FactoryDiscovery;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateFactoryTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    /**
     * Registry key for (is_a, requested file). Falls back to the bare is_a when
     * no file is named, or when the registered factory already sits in it.
     */
    private function keyFor(string $isa, ?string $cif): string
    {
        if ($cif === null || !isset($this->factories[$isa])) {
            return $isa;
        }
        if ((string) $this->factories[$isa]['factory']->entityContainedIn === $cif) {
            return $isa;
        }

        return FactoryDiscovery::qualifiedName($isa, $cif);
    }

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

        // Naming a file that differs from the registered one is a request for
        // ANOTHER facet, not a duplicate — previously it answered "already
        // exists" and silently discarded the file.
        $isa = $name;
        $name = $this->keyFor($isa, $args['contained_in_file'] ?? null);

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

        $cif = $args['contained_in_file'] ?? $isa . '_file';
        WriteGuard::forAccess($this->system, $this->access)?->assertCanCreateFactory($cif);
        $options = ['brothers' => [], 'joined' => []];
        // The registry key may be qualified (`note@alice_file`); the is_a is not.
        $factory = new EntityFactory($isa, $cif, $this->system);

        $this->factories[$name] = [
            'factory' => $factory,
            'options' => $options,
        ];
        $this->factoryMeta[$name] = [
            'isa' => $isa,
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
