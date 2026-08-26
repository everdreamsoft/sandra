<?php
declare(strict_types=1);

namespace SandraCore\Acl;

use PDO;
use SandraCore\DatabaseAdapter;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Files are declared, never a side effect.
 *
 * A file is the unit of the ACL: creating one creates a permission boundary.
 * Yet until now a file came into being incidentally — name an unknown
 * `contained_in_file` on `sandra_create_entity` and the concept was minted on
 * the spot. Nothing declared the intent, nothing decided whether the new
 * boundary should be visible, and nothing could tell a real file from a concept
 * that merely happened to be pointed at.
 *
 * So a file is now marked:
 *
 *   user_16_file --is_a--> sandra_file
 *
 * and, when asked for, hidden from the catalogue by being filed itself:
 *
 *   user_16_file --contained_in_file--> sandra_architect_file
 *
 * That second triplet is what stops `sandra_list_concepts` and
 * `sandra_find_concept` handing out the list of every file in the graph — and,
 * as a side effect, hides the grant triplets too, since their target is a file:
 *
 *   alice --sandra_allow_access--> user_16_file
 *                                  ^ hidden, so the link is hidden
 *
 * It is a CHOICE per file: a deliberately public file stays unfiled.
 *
 * A file must be filed ONLY under the architect file. A second, readable file
 * would make it visible again through the endpoint rule — one does not
 * un-publish by publishing again (see TripletVisibility).
 */
final class FileManager
{
    public const ARCHITECT_FILE = 'sandra_architect_file';
    public const SYSTEM_FILE = 'sandra_system_file';
    public const FILE_ISA = 'sandra_file';

    /**
     * Vocabulary that must never be minted, renamed or reused by a caller.
     * A fixed list rather than a `sandra_*` convention: reserving a prefix for
     * all time is a heavier promise than adding a name when one is needed.
     */
    public const RESERVED_CONCEPTS = [
        AclResolver::HAS_ROLE,
        AclResolver::ALLOW_ACCESS,
        AclResolver::ALLOW_WRITE,
        AclResolver::WILDCARD_FILE,
        AclResolver::EVERYONE_ROLE,
        self::ARCHITECT_FILE,
        self::SYSTEM_FILE,
        self::FILE_ISA,
        'contained_in_file',
        'is_a',
    ];

    public function __construct(private readonly System $system)
    {
    }

    public static function isReserved(string $shortname): bool
    {
        return in_array($shortname, self::RESERVED_CONCEPTS, true);
    }

    /**
     * Declare a file.
     *
     * @param bool     $architect file it under the architect file, so its name
     *                            leaves the catalogue
     * @param int|null $grantTo   principal to grant read+write on it
     *
     * @return int the file concept id
     */
    public function create(string $name, bool $architect = true, ?int $grantTo = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A file needs a name.');
        }
        if (self::isReserved($name)) {
            throw new AccessDeniedException("'$name' is reserved system vocabulary.");
        }

        $sc = $this->system->systemConcept;
        $fileId = (int) $sc->get($name);

        DatabaseAdapter::rawCreateTriplet($fileId, (int) $sc->get('is_a'), (int) $sc->get(self::FILE_ISA), $this->system);

        if ($architect) {
            $this->fileUnderArchitect($fileId, $name);
        }

        if ($grantTo !== null) {
            foreach ([AclResolver::ALLOW_ACCESS, AclResolver::ALLOW_WRITE] as $verb) {
                DatabaseAdapter::rawCreateTriplet($grantTo, (int) $sc->get($verb), $fileId, $this->system);
            }
        }

        return $fileId;
    }

    /**
     * Is this name a usable file — declared, or already holding entities?
     *
     * The second clause is what keeps every graph that predates this rule
     * working: a file that already contains something was evidently intended,
     * whoever created it. Only a file that exists NOWHERE yet is refused, which
     * is exactly the "created as a side effect" case.
     */
    public function isDeclared(string $name): bool
    {
        $sc = $this->system->systemConcept;
        $fileId = $sc->get($name, null, false);
        if (!is_numeric($fileId) || (int) $fileId <= 0) {
            return false;
        }
        $fileId = (int) $fileId;

        if ($this->hasLink($fileId, (int) $sc->get('is_a'), (int) $sc->get(self::FILE_ISA))) {
            return true;
        }

        return $this->holdsSomething($fileId, (int) $sc->get('contained_in_file'));
    }

    /**
     * One-pass hardening of a graph that predates all this: declare every file
     * that is already in use, file them all under the architect file, and put
     * the ACL vocabulary out of the catalogue's reach.
     *
     * @return array{files: int, vocabulary: int}
     */
    public function hardenExistingGraph(bool $architect = true): array
    {
        $sc = $this->system->systemConcept;
        $cifId = (int) $sc->get('contained_in_file');
        $architectId = (int) $sc->get(self::ARCHITECT_FILE);
        $systemId = (int) $sc->get(self::SYSTEM_FILE);
        $isaId = (int) $sc->get('is_a');
        $fileIsaId = (int) $sc->get(self::FILE_ISA);

        // Every distinct target of a contained_in_file link IS a file.
        $rows = QueryExecutor::fetchAll(
            $this->system->getConnection(),
            "SELECT DISTINCT idConceptTarget FROM `{$this->system->linkTable}`
             WHERE idConceptLink = :cif AND flag != :deleted",
            [':cif' => [$cifId, PDO::PARAM_INT], ':deleted' => [(int) $this->system->deletedUNID, PDO::PARAM_INT]]
        ) ?? [];

        $files = 0;
        foreach ($rows as $row) {
            $fileId = (int) $row['idConceptTarget'];
            // The architect file itself stays bare: filing it under itself is a
            // recursion for nothing, and knowing its name grants nothing.
            if ($fileId === $architectId || $fileId === $systemId) {
                continue;
            }
            DatabaseAdapter::rawCreateTriplet($fileId, $isaId, $fileIsaId, $this->system);
            if ($architect) {
                $this->fileUnderArchitect($fileId, (string) $sc->getShortname($fileId));
            }
            $files++;
        }

        $vocabulary = 0;
        foreach (self::RESERVED_CONCEPTS as $name) {
            if ($name === self::ARCHITECT_FILE || $name === self::SYSTEM_FILE) {
                continue;
            }
            // `is_a` and `contained_in_file` are the machinery of containment
            // itself; filing them would be circular and buys nothing, since a
            // VERB is never endpoint-filtered.
            if ($name === 'is_a' || $name === 'contained_in_file') {
                continue;
            }
            DatabaseAdapter::rawCreateTriplet((int) $sc->get($name), $cifId, $systemId, $this->system);
            $vocabulary++;
        }

        return ['files' => $files, 'vocabulary' => $vocabulary];
    }

    /**
     * File a file under the architect file, WITH refs.
     *
     * The refs are not decoration. An entity is materialised from its
     * references (populateLocal reads them through getReferences on the cif
     * link), so a facet carrying none is invisible to every query — the
     * architect file would hide names and hand nothing back, a marker one can
     * write but never read. With a `name` ref it becomes a real index:
     *
     *   MATCH sandra_file IN sandra_architect_file
     *
     * answers with the list of every file, to whoever is granted that file.
     */
    private function fileUnderArchitect(int $fileId, string $name): void
    {
        $sc = $this->system->systemConcept;
        $link = (int) DatabaseAdapter::rawCreateTriplet(
            $fileId, (int) $sc->get('contained_in_file'), (int) $sc->get(self::ARCHITECT_FILE), $this->system
        );

        DatabaseAdapter::rawCreateReference($link, (int) $sc->get('name'), $name, $this->system);
        DatabaseAdapter::rawCreateReference($link, (int) $sc->get('declaredAt'), (string) time(), $this->system);
    }

    private function hasLink(int $subject, int $verb, int $target): bool
    {
        $rows = QueryExecutor::fetchAll(
            $this->system->getConnection(),
            "SELECT 1 FROM `{$this->system->linkTable}`
             WHERE idConceptStart = :s AND idConceptLink = :v AND idConceptTarget = :t AND flag != :deleted LIMIT 1",
            [
                ':s' => [$subject, PDO::PARAM_INT], ':v' => [$verb, PDO::PARAM_INT],
                ':t' => [$target, PDO::PARAM_INT], ':deleted' => [(int) $this->system->deletedUNID, PDO::PARAM_INT],
            ]
        ) ?? [];

        return $rows !== [];
    }

    private function holdsSomething(int $fileId, int $cifId): bool
    {
        $rows = QueryExecutor::fetchAll(
            $this->system->getConnection(),
            "SELECT 1 FROM `{$this->system->linkTable}`
             WHERE idConceptLink = :cif AND idConceptTarget = :file AND flag != :deleted LIMIT 1",
            [
                ':cif' => [$cifId, PDO::PARAM_INT], ':file' => [$fileId, PDO::PARAM_INT],
                ':deleted' => [(int) $this->system->deletedUNID, PDO::PARAM_INT],
            ]
        ) ?? [];

        return $rows !== [];
    }
}
