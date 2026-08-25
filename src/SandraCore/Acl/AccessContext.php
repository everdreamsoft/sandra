<?php
declare(strict_types=1);

namespace SandraCore\Acl;

/**
 * Resolved grants of an acting principal — mirror of the JS AccessContext
 * (@everdreamsoft/sandra src/acl/acl.ts). Immutable; resolve via AclResolver.
 */
class AccessContext
{
    public int $principalId;
    /** @var array<int, true> */
    public array $roleIds;
    /** @var array<int, true> concept ids of readable files */
    public array $allowedRead;
    /** @var array<int, true> concept ids of writable files */
    public array $allowedWrite;
    public bool $readAll;
    public bool $writeAll;

    /**
     * @param int[] $roleIds
     * @param int[] $allowedRead
     * @param int[] $allowedWrite
     */
    public function __construct(
        int $principalId,
        array $roleIds,
        array $allowedRead,
        array $allowedWrite,
        bool $readAll,
        bool $writeAll
    ) {
        $this->principalId = $principalId;
        $this->roleIds = array_fill_keys($roleIds, true);
        $this->allowedRead = array_fill_keys($allowedRead, true);
        $this->allowedWrite = array_fill_keys($allowedWrite, true);
        $this->readAll = $readAll;
        $this->writeAll = $writeAll;
    }

    public function canRead(int $fileConceptId): bool
    {
        return $this->readAll || isset($this->allowedRead[$fileConceptId]);
    }

    public function canWrite(int $fileConceptId): bool
    {
        return $this->writeAll || isset($this->allowedWrite[$fileConceptId]);
    }

    /**
     * True when the principal holds a write grant of any kind. Distinguishes a
     * writer scoped to one file from a purely read-only principal, which is
     * what gates shared-vocabulary acts like minting a concept.
     */
    public function canWriteAnything(): bool
    {
        return $this->writeAll || $this->allowedWrite !== [];
    }

    /** Admin = holds the write wildcard; may edit ACL triplets / create files. */
    public function isAdmin(): bool
    {
        return $this->writeAll;
    }

    /** @return int[] principal + roles (the grant subjects) */
    public function subjects(): array
    {
        return array_merge([$this->principalId], array_keys($this->roleIds));
    }
}
