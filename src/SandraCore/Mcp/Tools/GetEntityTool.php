<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\AclResolver;
use SandraCore\Acl\TripletVisibility;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class GetEntityTool implements McpToolInterface, AclAwareToolInterface
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
        return 'sandra_get_entity';
    }

    public function description(): string
    {
        return 'Get a single entity by its concept ID from a factory, including refs, brothers, and joined entities. Does NOT load the entire factory into memory.';
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
                'id' => [
                    'type' => 'integer',
                    'description' => 'The concept ID of the entity',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: list of ref field names to include. If omitted, all fields are returned.',
                ],
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'If true, include the entity data storage (long text content). Default false.',
                ],
            ],
            'required' => ['factory', 'id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $entry = $this->factories[$name];
        $factory = $entry['factory'];

        $options = $entry['options'];
        $id = (int)($args['id'] ?? 0);

        // Word for word the "not found" raised further down when the entity is
        // genuinely absent: a distinguishable refusal would tell the caller the
        // factory exists and is merely out of reach.
        if ($this->access !== null
            && !AclResolver::fileReadable($this->system, $this->access, (string)$factory->entityContainedIn)) {
            throw new \InvalidArgumentException("Entity with id $id not found in factory '$name'");
        }

        $fields = $args['fields'] ?? null;
        if ($fields !== null) {
            $options['fields'] = $fields;
        }
        if (!empty($args['include_storage'])) {
            $options['include_storage'] = true;
        }

        // Load only this single entity via a fresh factory with pre-set conceptArray
        $singleFactory = new EntityFactory(
            $factory->entityIsa,
            $factory->entityContainedIn,
            $this->system
        );
        $singleFactory->conceptArray = [$id];
        $singleFactory->populateLocal();

        $entity = null;
        foreach ($singleFactory->getEntities() as $e) {
            if ((int)$e->subjectConcept->idConcept === $id) {
                $entity = $e;
                break;
            }
        }

        if ($entity === null) {
            throw new \InvalidArgumentException("Entity with id $id not found in factory '$name'");
        }

        return EntitySerializer::serialize($entity, $options);
    }
}
