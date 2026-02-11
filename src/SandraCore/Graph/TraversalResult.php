<?php
declare(strict_types=1);

namespace SandraCore\Graph;

use SandraCore\Entity;

class TraversalResult implements \Countable, \IteratorAggregate
{
    /** @var array<int, Entity[]> depth => entities */
    private array $byDepth = [];

    /** @var array<int|string, Entity> conceptId => entity (dedup) */
    private array $entities = [];

    private bool $cycleDetected = false;

    public function addEntity(Entity $entity, int $depth): void
    {
        $id = $entity->subjectConcept->idConcept;
        if (!isset($this->entities[$id])) {
            $this->entities[$id] = $entity;
            $this->byDepth[$depth][] = $entity;
        }
    }

    /** @return Entity[] */
    public function getEntities(): array
    {
        return array_values($this->entities);
    }

    /** @return Entity[] */
    public function getAtDepth(int $depth): array
    {
        return $this->byDepth[$depth] ?? [];
    }

    public function getMaxDepth(): int
    {
        if (empty($this->byDepth)) {
            return 0;
        }
        return max(array_keys($this->byDepth));
    }

    public function markCycle(): void
    {
        $this->cycleDetected = true;
    }

    public function hasCycle(): bool
    {
        return $this->cycleDetected;
    }

    public function count(): int
    {
        return count($this->entities);
    }

    public function isEmpty(): bool
    {
        return empty($this->entities);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(array_values($this->entities));
    }
}
