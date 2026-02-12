<?php
declare(strict_types=1);

namespace SandraCore\Query;

use SandraCore\CommonFunctions;
use SandraCore\DatabaseAdapter;
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

    public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
    {
        if ($value === null) {
            return $this->whereRef($field, '=', $operatorOrValue);
        }
        return $this->whereRef($field, (string)$operatorOrValue, $value);
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

        // Ref-only: SQL search -> conceptArray -> populateLocal
        if ($this->hasRefClauses() && !$this->hasBrotherClauses()) {
            $conceptIds = $this->searchRefConceptIds();
            if (empty($conceptIds)) {
                return new QueryResult([], 0, $this->limitValue, $this->offsetValue);
            }
            $factory->conceptArray = $conceptIds;
            $factory->populateLocal();
            $entities = $factory->getEntities() ?: [];
            $total = count($entities);
            $entities = array_values($entities);

            if ($this->offsetValue !== null) {
                $entities = array_slice($entities, $this->offsetValue);
            }
            if ($this->limitValue !== null) {
                $entities = array_slice($entities, 0, $this->limitValue);
            }

            return new QueryResult($entities, $total, $this->limitValue, $this->offsetValue);
        }

        // Combined brother + ref: brother at SQL level, then intersect with ref SQL results
        if ($this->hasRefClauses() && $this->hasBrotherClauses()) {
            $refConceptIds = $this->searchRefConceptIds();
            if (empty($refConceptIds)) {
                return new QueryResult([], 0, $this->limitValue, $this->offsetValue);
            }
            $refIdSet = array_flip($refConceptIds);

            // Brother filter is already applied via setFilter in buildFactory
            $factory->populateLocal(null, 0, $this->orderDirection, $this->orderByRef);
            $allEntities = $factory->getEntities() ?: [];

            // Keep only entities matching ref SQL results
            $entities = array_values(array_filter($allEntities, function (Entity $e) use ($refIdSet) {
                return isset($refIdSet[$e->subjectConcept->idConcept]);
            }));

            $total = count($entities);
            if ($this->offsetValue !== null) {
                $entities = array_slice($entities, $this->offsetValue);
            }
            if ($this->limitValue !== null) {
                $entities = array_slice($entities, 0, $this->limitValue);
            }

            return new QueryResult($entities, $total, $this->limitValue, $this->offsetValue);
        }

        // Brother-only or no filters: standard path
        $entities = $this->executeQuery($factory);
        $total = count($entities);

        return new QueryResult($entities, $total, $this->limitValue, $this->offsetValue);
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

        // Ref clauses present: use SQL search for concept IDs
        $conceptIds = $this->searchRefConceptIds();
        if (empty($conceptIds)) {
            return 0;
        }

        if (!$this->hasBrotherClauses()) {
            return count($conceptIds);
        }

        // Combined: need to intersect with brother-filtered set
        $factory = $this->buildFactory();
        $factory->populateLocal(null, 0, $this->orderDirection, $this->orderByRef);
        $allEntities = $factory->getEntities() ?: [];
        $refIdSet = array_flip($conceptIds);

        $count = 0;
        foreach ($allEntities as $entity) {
            if (isset($refIdSet[$entity->subjectConcept->idConcept])) {
                $count++;
            }
        }
        return $count;
    }

    private function hasRefClauses(): bool
    {
        return !empty($this->refClauses);
    }

    private function hasBrotherClauses(): bool
    {
        return !empty($this->brotherClauses);
    }

    /**
     * Use SQL-level ref search to find matching concept IDs.
     * Same pattern as populateFromSearchResults: filter at SQL, get IDs only.
     *
     * @return array Concept IDs matching ALL ref clauses (AND logic)
     */
    private function searchRefConceptIds(): array
    {
        $system = $this->originalFactory->system;

        $entityRefContainer = (string)CommonFunctions::somethingToConceptId(
            $this->originalFactory->entityReferenceContainer,
            $system
        );
        $entityContainedIn = (string)CommonFunctions::somethingToConceptId(
            $this->originalFactory->entityContainedIn,
            $system
        );

        $candidateIds = null;

        foreach ($this->refClauses as $clause) {
            $ids = DatabaseAdapter::searchConceptByRef(
                $system,
                $clause->field,
                $clause->operator,
                (string)$clause->value,
                $entityRefContainer,
                $entityContainedIn
            );

            if ($ids === null || empty($ids)) {
                return [];
            }

            // Intersect: all clauses must match (AND logic)
            if ($candidateIds === null) {
                $candidateIds = $ids;
            } else {
                $candidateIds = array_values(array_intersect($candidateIds, $ids));
            }

            if (empty($candidateIds)) {
                return [];
            }
        }

        return $candidateIds ?? [];
    }

    private function buildFactory(): EntityFactory
    {
        $system = $this->originalFactory->system;
        $factory = new EntityFactory(
            $this->originalFactory->entityIsa,
            $this->originalFactory->entityContainedIn,
            $system
        );

        // Propagate default limit
        $factory->setDefaultLimit($this->originalFactory->getDefaultLimit());

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
        $limit = null; // uses factory's defaultLimit
        $offset = 0;

        if ($this->limitValue !== null) {
            $limit = $this->limitValue;
        }
        if ($this->offsetValue !== null) {
            $offset = $this->offsetValue;
        }

        $factory->populateLocal(
            $limit,
            $offset,
            $this->orderDirection,
            $this->orderByRef
        );

        return $factory->getEntities() ?: [];
    }
}
