<?php
declare(strict_types=1);

namespace SandraCore\Query;

use SandraCore\Entity;

class QueryResult implements \Countable, \IteratorAggregate
{
    private array $entities;
    private int $total;
    private ?int $limit;
    private ?int $offset;

    public function __construct(array $entities, int $total, ?int $limit = null, ?int $offset = null)
    {
        $this->entities = array_values($entities);
        $this->total = $total;
        $this->limit = $limit;
        $this->offset = $offset;
    }

    public function count(): int
    {
        return count($this->entities);
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function first(): ?Entity
    {
        return $this->entities[0] ?? null;
    }

    public function last(): ?Entity
    {
        if (empty($this->entities)) {
            return null;
        }
        return $this->entities[count($this->entities) - 1];
    }

    public function toArray(): array
    {
        return $this->entities;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entities);
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function isEmpty(): bool
    {
        return empty($this->entities);
    }
}
