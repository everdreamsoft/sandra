<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\AclResolver;
use SandraCore\Acl\TripletVisibility;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class FindConceptTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_find_concept';
    }

    public function description(): string
    {
        return 'Find a system concept by exact shortname. Returns the concept ID if found. '
            . 'Unlike create_concept, this never creates anything. '
            . 'For partial/wildcard search, use sandra_search or sandra_list_concepts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The concept shortname to search for (exact match)',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['name'] ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException('Concept name cannot be empty');
        }

        $id = $this->system->systemConcept->get($name, null, false);

        // A concept can carry its own contained_in_file — that is how a private
        // tag is made private. Resolving its name would give it away.
        $visibility = TripletVisibility::forAccess($this->system, $this->access);
        if ($id !== null && $visibility !== null && $visibility->visibleConcepts([(int)$id]) === []) {
            $id = null;
        }

        if ($id === null) {
            return [
                'found' => false,
                'name' => $name,
                'id' => null,
            ];
        }

        return [
            'found' => true,
            'name' => $name,
            'id' => (int)$id,
        ];
    }
}
