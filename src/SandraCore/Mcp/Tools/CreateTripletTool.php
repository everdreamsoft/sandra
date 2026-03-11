<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Exception\ConceptNotFoundException;
use SandraCore\DatabaseAdapter;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateTripletTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_create_triplet';
    }

    public function description(): string
    {
        return 'Create a triplet (subject → verb → target) linking two concepts. Concepts can be specified by ID or shortname. Returns the triplet link ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => [
                    'type' => ['string', 'integer'],
                    'description' => 'Subject concept: ID (integer) or shortname (string)',
                ],
                'verb' => [
                    'type' => ['string', 'integer'],
                    'description' => 'Verb concept: ID (integer) or shortname (string)',
                ],
                'target' => [
                    'type' => ['string', 'integer'],
                    'description' => 'Target concept: ID (integer) or shortname (string)',
                ],
            ],
            'required' => ['subject', 'verb', 'target'],
        ];
    }

    public function execute(array $args): mixed
    {
        $subjectId = $this->resolveConceptId($args['subject'] ?? '');
        $verbId = $this->resolveConceptId($args['verb'] ?? '');
        $targetId = $this->resolveConceptId($args['target'] ?? '');

        $linkId = DatabaseAdapter::rawCreateTriplet($subjectId, $verbId, $targetId, $this->system);

        if ($linkId === null) {
            throw new \RuntimeException('Failed to create triplet');
        }

        return [
            'linkId' => (int)$linkId,
            'subjectId' => $subjectId,
            'verbId' => $verbId,
            'targetId' => $targetId,
        ];
    }

    private function resolveConceptId(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value) && $value !== '') {
            $id = $this->system->systemConcept->get($value, null, false);
            if ($id !== null) {
                return (int)$id;
            }
            throw new \InvalidArgumentException("Concept not found: '$value'. Create it first with sandra_create_concept.");
        }

        throw new \InvalidArgumentException('Invalid concept value: must be an ID or shortname');
    }
}
