<?php
declare(strict_types=1);

namespace SandraCore\Acl;

use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Resolves a principal's AccessContext from the graph. Same conventions as
 * the JS library:
 *
 *   principal --has_role--> role                      (closure, depth <= 3)
 *   principal|role --sandra_allow_access--> file      (read)
 *   principal|role --sandra_allow_write--> file       (write)
 *   sandra_all_files target = wildcard, sandra_everyone = implicit role
 *
 * Cost: 2-5 small indexed queries, independent of graph size. Resolve once
 * per request (PHP lifecycle) — no cross-request cache needed.
 */
class AclResolver
{
    public const HAS_ROLE = 'has_role';
    public const ALLOW_ACCESS = 'sandra_allow_access';
    public const ALLOW_WRITE = 'sandra_allow_write';
    public const WILDCARD_FILE = 'sandra_all_files';
    public const EVERYONE_ROLE = 'sandra_everyone';
    public const PROTECTED_VERBS = [self::HAS_ROLE, self::ALLOW_ACCESS, self::ALLOW_WRITE];

    private const ROLE_MAX_DEPTH = 3;

    public static function resolve(System $system, int|string $principal): AccessContext
    {
        $sc = $system->systemConcept;
        $principalId = is_int($principal) ? $principal : (int) $sc->get($principal, null, false);
        if ($principalId <= 0) {
            throw new \InvalidArgumentException("Unknown principal \"$principal\"");
        }

        // role closure
        $roleIds = [];
        $hasRoleId = self::findConcept($system, self::HAS_ROLE);
        if ($hasRoleId !== null) {
            $frontier = [$principalId];
            for ($depth = 0; $depth < self::ROLE_MAX_DEPTH && $frontier !== []; $depth++) {
                $found = self::targetsOf($system, $frontier, $hasRoleId);
                $frontier = [];
                foreach ($found as $id) {
                    if ($id !== $principalId && !in_array($id, $roleIds, true)) {
                        $roleIds[] = $id;
                        $frontier[] = $id;
                    }
                }
            }
        }
        $everyoneId = self::findConcept($system, self::EVERYONE_ROLE);
        if ($everyoneId !== null && !in_array($everyoneId, $roleIds, true)) {
            $roleIds[] = $everyoneId;
        }

        $subjects = array_merge([$principalId], $roleIds);
        $wildcardId = self::findConcept($system, self::WILDCARD_FILE);

        [$readFiles, $readAll] = self::collectGrants($system, $subjects, self::ALLOW_ACCESS, $wildcardId);
        [$writeFiles, $writeAll] = self::collectGrants($system, $subjects, self::ALLOW_WRITE, $wildcardId);

        return new AccessContext($principalId, $roleIds, $readFiles, $writeFiles, $readAll, $writeAll);
    }

    /** @return array{0: int[], 1: bool} */
    private static function collectGrants(System $system, array $subjects, string $verb, ?int $wildcardId): array
    {
        $verbId = self::findConcept($system, $verb);
        if ($verbId === null) {
            return [[], false];
        }
        $targets = self::targetsOf($system, $subjects, $verbId);
        $all = $wildcardId !== null && in_array($wildcardId, $targets, true);
        $files = array_values(array_filter($targets, static fn (int $id): bool => $id !== $wildcardId));
        return [$files, $all];
    }

    /** True when the principal may read entities contained in this file. */
    public static function fileReadable(System $system, AccessContext $access, string $fileShortname): bool
    {
        if ($access->readAll) {
            return true;
        }
        $fileId = self::findConcept($system, $fileShortname);
        return $fileId !== null && $access->canRead($fileId);
    }

    /** Resolve a shortname WITHOUT creating it. */
    private static function findConcept(System $system, string $shortname): ?int
    {
        $id = $system->systemConcept->get($shortname, null, false);
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * Active targets of (subjects x verb). One indexed IN() query.
     *
     * @param int[] $subjects
     * @return int[]
     */
    private static function targetsOf(System $system, array $subjects, int $verbId): array
    {
        if ($subjects === []) {
            return [];
        }
        $subjects = array_map('intval', $subjects);
        $placeholders = implode(', ', array_fill(0, count($subjects), '?'));
        $sql = "SELECT idConceptTarget FROM {$system->linkTable}
                WHERE idConceptLink = ? AND idConceptStart IN ($placeholders) AND flag != ?";
        // positional `?` binds are 1-indexed in PDO
        $bound = [];
        foreach (array_merge([$verbId], $subjects, [(int) $system->deletedUNID]) as $i => $value) {
            $bound[$i + 1] = $value;
        }

        $stmt = QueryExecutor::execute($system->getConnection(), $sql, $bound);
        if ($stmt === null) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN, 0) as $value) {
            $out[] = (int) $value;
        }
        return $out;
    }
}
