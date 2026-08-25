<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\WriteGuard;
use SandraCore\Exception\ConceptNotFoundException;
use SandraCore\DatabaseAdapter;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * ACL-aware write: the guard goes to rawCreateTriplet, which decides from the
 * endpoints' files — a link may be written only if every endpoint carrying a
 * contained_in_file sits in a writable one.
 */
class CreateTripletTool implements McpToolInterface, AclAwareToolInterface
{
    private System $system;
    private ?AccessContext $access = null;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    public function name(): string
    {
        return 'sandra_create_triplet';
    }

    public function description(): string
    {
        return 'Create a triplet (subject → verb → target) linking two concepts. Concepts can be specified by ID or shortname. '
            . 'Optionally attach a long-text "storage" payload (MEDIUMTEXT) directly to the triplet — useful for notes, '
            . 'descriptions, JSON, or markdown anchored on the link rather than on either endpoint. Returns the triplet link ID.';
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
                'storage' => [
                    'type' => 'string',
                    'description' => 'Optional long-text payload attached to the triplet (article body, JSON, markdown, logs...). Stored separately from refs.',
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

        $linkId = DatabaseAdapter::rawCreateTriplet(
            $subjectId,
            $verbId,
            $targetId,
            $this->system,
            0,
            true,
            WriteGuard::forAccess($this->system, $this->access)
        );

        if ($linkId === null) {
            throw new \RuntimeException('Failed to create triplet');
        }

        $result = [
            'linkId' => (int)$linkId,
            'subjectId' => $subjectId,
            'verbId' => $verbId,
            'targetId' => $targetId,
        ];

        $storage = $args['storage'] ?? null;
        if ($storage !== null && $storage !== '') {
            DatabaseAdapter::rawSetStorage((int)$linkId, (string)$storage, $this->system);
            $result['storageSet'] = true;
        }

        return $result;
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
