<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\AclResolver;
use SandraCore\EntityFactory;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class ListFactoriesTool implements McpToolInterface, AclAwareToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private ?System $system;
    private ?AccessContext $access = null;

    public function __construct(array &$factories, ?System $system = null)
    {
        $this->factories = &$factories;
        $this->system = $system;
    }

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
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
            if ($this->access !== null && $this->system !== null
                && !AclResolver::fileReadable($this->system, $this->access, (string)$factory->entityContainedIn)) {
                continue; // graph ACL: hidden file
            }
            $result[] = [
                'name' => $name,
                'entityIsa' => $factory->entityIsa,
                'entityContainedIn' => $factory->entityContainedIn,
            ];
        }
        return $result;
    }
}
