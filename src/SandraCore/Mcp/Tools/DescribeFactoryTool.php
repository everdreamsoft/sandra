<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;

class DescribeFactoryTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;

    public function __construct(array &$factories)
    {
        $this->factories = &$factories;
    }

    public function name(): string
    {
        return 'sandra_describe_factory';
    }

    public function description(): string
    {
        return 'Describe a factory: its schema (reference fields), entity type, and count.';
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
            ],
            'required' => ['factory'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];

        $refFields = [];
        $refMap = $factory->getReferenceMap();
        if (is_array($refMap)) {
            foreach ($refMap as $concept) {
                $displayName = $concept->getDisplayName();
                if ($displayName !== null && $displayName !== 'creationTimestamp') {
                    $refFields[] = $displayName;
                }
            }
        }

        $count = $factory->countEntitiesOnRequest();

        return [
            'name' => $name,
            'entityIsa' => $factory->entityIsa,
            'entityContainedIn' => $factory->entityContainedIn,
            'referenceFields' => $refFields,
            'count' => $count,
        ];
    }
}
