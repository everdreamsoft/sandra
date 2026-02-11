<?php
declare(strict_types=1);

namespace SandraCore\Events;

use SandraCore\Entity;
use SandraCore\EntityFactory;

class EntityEvent
{
    public const ENTITY_CREATING = 'entity.creating';
    public const ENTITY_CREATED = 'entity.created';
    public const ENTITY_UPDATED = 'entity.updated';
    public const BROTHER_LINKED = 'brother.linked';
    public const ENTITY_DELETING = 'entity.deleting';
    public const ENTITY_DELETED = 'entity.deleted';

    private string $name;
    private ?Entity $entity;
    private EntityFactory $factory;
    private array $data;
    private bool $propagationStopped = false;

    public function __construct(string $name, EntityFactory $factory, ?Entity $entity = null, array $data = [])
    {
        $this->name = $name;
        $this->factory = $factory;
        $this->entity = $entity;
        $this->data = $data;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEntity(): ?Entity
    {
        return $this->entity;
    }

    public function getFactory(): EntityFactory
    {
        return $this->factory;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
