<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\CommonFunctions;
use SandraCore\Acl\WriteGuard;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class LinkEntitiesTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private System $system;

    public function __construct(array &$factories, System $system)
    {
        $this->factories = &$factories;
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_link_entities';
    }

    public function description(): string
    {
        return 'Link a source entity to a target via a brother verb relationship. Does NOT load the entire factory into memory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name containing the source entity',
                ],
                'sourceId' => [
                    'type' => 'integer',
                    'description' => 'Concept ID of the source entity',
                ],
                'verb' => [
                    'type' => 'string',
                    'description' => 'The verb (relationship type) for the link',
                ],
                'target' => [
                    'description' => 'Target concept name (string) or concept ID (integer)',
                ],
                'refs' => [
                    'type' => 'object',
                    'description' => 'Optional references to attach to the brother link',
                ],
            ],
            'required' => ['factory', 'sourceId', 'verb', 'target'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $factory = $this->factories[$name]['factory'];
        $sourceId = (int)($args['sourceId'] ?? 0);
        $verb = $args['verb'] ?? '';
        $target = $args['target'] ?? '';
        $refs = $args['refs'] ?? [];

        // setBrotherEntity() lands as a triplet, so it answers to the same
        // endpoint rule — checked before the entity is loaded, so a denial does
        // not double as an existence oracle.
        $guard = WriteGuard::forAccess($this->system, $this->access);
        if ($guard !== null) {
            // Same resolution as setBrotherEntity(), so the guard judges the
            // exact ids that will be written.
            $guard->assertCanLink(
                $sourceId,
                (int)CommonFunctions::somethingToConceptId($verb, $this->system),
                (int)CommonFunctions::somethingToConceptId($target, $this->system)
            );
        }

        // Load only the source entity via a fresh factory with pre-set conceptArray
        $singleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $singleFactory->conceptArray = [$sourceId];
        $singleFactory->populateLocal();

        $entity = null;
        foreach ($singleFactory->getEntities() as $e) {
            if ((int)$e->subjectConcept->idConcept === $sourceId) {
                $entity = $e;
                break;
            }
        }

        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $sourceId not found in factory '$name'");
        }

        $entity->setBrotherEntity($verb, $target, $refs);

        return [
            'linked' => true,
            'source' => $sourceId,
            'verb' => $verb,
            'target' => $target,
        ];
    }
}
