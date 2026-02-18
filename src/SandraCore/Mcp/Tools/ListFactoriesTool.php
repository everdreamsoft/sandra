<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;

class ListFactoriesTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_list_factories';
    }

    public function description(): string
    {
        return 'List all registered entity factories (data types) in the Sandra graph database.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $args): mixed
    {
        $result = [];
        foreach ($this->factories as $name => $entry) {
            $factory = $entry['factory'];
            $result[] = [
                'name' => $name,
                'entityIsa' => $factory->entityIsa,
                'entityContainedIn' => $factory->entityContainedIn,
            ];
        }
        return $result;
    }
}
