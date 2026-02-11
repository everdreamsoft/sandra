<?php
declare(strict_types=1);

namespace SandraCore;

class Reference implements Dumpable
{
    public ?Concept $refConcept;
    public ?Entity $refEntity;
    public mixed $refValue;
    private ?System $system;
    public mixed $refId;

    public function __construct(mixed $id, Concept $refConcept, Entity &$refEntity, mixed $refValue, System $system)
    {
        $this->refConcept = $refConcept;
        $this->refId = $id;
        $this->refEntity = $refEntity;
        $this->refValue = $refValue;
        $this->system = $system;
    }

    public function hasChangedFromDatabase(): bool
    {
        $inMemoryValue = $this->refValue;

        $newValue = $this->reload();

        if ($inMemoryValue == $newValue) {
            return false;
        } else {
            return true;
        }
    }

    public function reload(): mixed
    {
        $newValue = getReference($this->refConcept->idConcept, $this->refEntity->entityId);
        $this->refValue = $newValue;

        return $newValue;
    }

    public function save(mixed $newValue): mixed
    {
        DatabaseAdapter::rawCreateReference(
            $this->refEntity->entityId,
            $this->refConcept->idConcept,
            $newValue,
            $this->system
        );
        $this->refValue = $newValue;

        return $newValue;
    }

    public function dumpMeta()
    {
        return $this->refValue;
    }

    public function destroy(): void
    {
        $this->system = null;
        $this->refConcept = null;
        $this->refEntity = null;
        $this->refValue = null;
        $this->refId = null;
    }
}
