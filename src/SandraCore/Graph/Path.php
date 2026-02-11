<?php
declare(strict_types=1);

namespace SandraCore\Graph;

use SandraCore\Entity;

class Path
{
    /** @var Entity[] */
    private array $entities;

    public function __construct(array $entities = [])
    {
        $this->entities = $entities;
    }

    public function append(Entity $entity): self
    {
        $new = clone $this;
        $new->entities[] = $entity;
        return $new;
    }

    /** @return Entity[] */
    public function getEntities(): array
    {
        return $this->entities;
    }

    public function getLength(): int
    {
        $count = count($this->entities);
        return $count > 0 ? $count - 1 : 0;
    }

    public function getStart(): ?Entity
    {
        return $this->entities[0] ?? null;
    }

    public function getEnd(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return $this->entities[count($this->entities) - 1];
    }

    public function contains(Entity $entity): bool
    {
        $targetId = $entity->subjectConcept->idConcept;
        foreach ($this->entities as $e) {
            if ($e->subjectConcept->idConcept === $targetId) {
                return true;
            }
        }
        return false;
    }
}
