<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Exception\ConceptNotFoundException;
use SandraCore\DatabaseAdapter;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateConceptTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_create_concept';
    }

    public function description(): string
    {
        return 'Create an abstract concept (e.g. "god", "friend", "universe") and return its ID. If the concept already exists, returns the existing ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The concept shortname/code to create',
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

        // Check if concept already exists (forceCreate=false to avoid auto-creation)
        $existingId = $this->system->systemConcept->get($name, null, false);
        if ($existingId !== null) {
            return [
                'id' => (int)$existingId,
                'name' => $name,
                'created' => false,
            ];
        }

        // Create new concept via systemConcept->get with forceCreate=true
        $id = $this->system->systemConcept->get($name, null, true);

        return [
            'id' => (int)$id,
            'name' => $name,
            'created' => true,
        ];
    }
}
