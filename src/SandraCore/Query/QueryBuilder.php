<?php
declare(strict_types=1);

namespace SandraCore\Query;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\FactoryBase;

class QueryBuilder
{
    private FactoryBase $originalFactory;
    /** @var WhereClause[] */
    private array $brotherClauses = [];
    /** @var WhereClause[] */
    private array $refClauses = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private ?string $orderByRef = null;
    private string $orderDirection = 'ASC';

    public function __construct(FactoryBase $factory)
    {
        $this->originalFactory = $factory;
    }

    public function whereHasBrother(string $verb, mixed $target): self
    {
        $this->brotherClauses[] = new WhereClause(
            WhereClause::TYPE_BROTHER,
            $verb,
            '=',
            $target,
            false
        );
        return $this;
    }

    public function whereNotHasBrother(string $verb, mixed $target): self
    {
        $this->brotherClauses[] = new WhereClause(
            WhereClause::TYPE_BROTHER,
            $verb,
            '=',
            $target,
            true
        );
        return $this;
    }

    public function whereRef(string $field, string $operator, mixed $value): self
    {
        $this->refClauses[] = new WhereClause(
            WhereClause::TYPE_REF,
            $field,
            $operator,
            $value
        );
        return $this;
    }

    public function where(string $field, mixed $value): self
    {
        return $this->whereRef($field, '=', $value);
    }

    public function orderBy(string $ref, string $direction = 'ASC'): self
    {
        $this->orderByRef = $ref;
        $this->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;
        return $this;
    }

    public function get(): QueryResult
    {
        $factory = $this->buildFactory();
        $entities = $this->executeQuery($factory);

        // Apply post-load ref filters
        $filtered = $this->applyRefFilters($entities);

        $total = count($filtered);

        // Apply offset and limit on post-filtered results
        if ($this->hasRefClauses()) {
            if ($this->offsetValue !== null) {
                $filtered = array_slice($filtered, $this->offsetValue);
            }
            if ($this->limitValue !== null) {
                $filtered = array_slice($filtered, 0, $this->limitValue);
            }
        }

        return new QueryResult($filtered, $total, $this->limitValue, $this->offsetValue);
    }

    public function first(): ?Entity
    {
        $originalLimit = $this->limitValue;
        if (!$this->hasRefClauses()) {
            $this->limitValue = 1;
        }

        $result = $this->get();
        $this->limitValue = $originalLimit;

        return $result->first();
    }

    public function count(): int
    {
        if (!$this->hasRefClauses()) {
            $factory = $this->buildFactory();
            return $factory->countEntitiesOnRequest();
        }

        $factory = $this->buildFactory();
        $entities = $this->executeQuery($factory);
        $filtered = $this->applyRefFilters($entities);
        return count($filtered);
    }

    private function hasRefClauses(): bool
    {
        return !empty($this->refClauses);
    }

    private function buildFactory(): EntityFactory
    {
        $system = $this->originalFactory->system;
        $factory = new EntityFactory(
            $this->originalFactory->entityIsa,
            $this->originalFactory->entityContainedIn,
            $system
        );

        // Apply brother filters (SQL-level)
        foreach ($this->brotherClauses as $clause) {
            $factory->setFilter($clause->field, $clause->value, $clause->exclusion);
        }

        return $factory;
    }

    /**
     * @return Entity[]
     */
    private function executeQuery(EntityFactory $factory): array
    {
        $limit = 10000;
        $offset = 0;

        // Only pass limit/offset to SQL if there are no ref filters
        if (!$this->hasRefClauses()) {
            if ($this->limitValue !== null) {
                $limit = $this->limitValue;
            }
            if ($this->offsetValue !== null) {
                $offset = $this->offsetValue;
            }
        }

        $factory->populateLocal(
            $limit,
            $offset,
            $this->orderDirection,
            $this->orderByRef
        );

        return $factory->getEntities() ?: [];
    }

    /**
     * @param Entity[] $entities
     * @return Entity[]
     */
    private function applyRefFilters(array $entities): array
    {
        if (empty($this->refClauses)) {
            return $entities;
        }

        return array_values(array_filter($entities, function (Entity $entity) {
            foreach ($this->refClauses as $clause) {
                $entityValue = $entity->get($clause->field);

                if (!$this->matchesCondition($entityValue, $clause->operator, $clause->value)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function matchesCondition(mixed $entityValue, string $operator, mixed $conditionValue): bool
    {
        return match ($operator) {
            '=' => $entityValue == $conditionValue,
            '!=' => $entityValue != $conditionValue,
            '>' => is_numeric($entityValue) && is_numeric($conditionValue) && (float)$entityValue > (float)$conditionValue,
            '>=' => is_numeric($entityValue) && is_numeric($conditionValue) && (float)$entityValue >= (float)$conditionValue,
            '<' => is_numeric($entityValue) && is_numeric($conditionValue) && (float)$entityValue < (float)$conditionValue,
            '<=' => is_numeric($entityValue) && is_numeric($conditionValue) && (float)$entityValue <= (float)$conditionValue,
            'like' => $entityValue !== null && $this->matchesLike((string)$entityValue, (string)$conditionValue),
            default => false,
        };
    }

    private function matchesLike(string $value, string $pattern): bool
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        // Re-apply wildcard conversions after preg_quote escaped them
        $regex = str_replace(['\\%', '\\_'], ['%', '_'], $regex);
        $regex = str_replace(['%', '_'], ['.*', '.'], $regex);
        return (bool)preg_match($regex, $value);
    }
}
