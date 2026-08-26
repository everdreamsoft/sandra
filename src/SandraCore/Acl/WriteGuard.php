<?php
declare(strict_types=1);

namespace SandraCore\Acl;

use PDO;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Every write decision of the graph ACL, in one place.
 *
 * A triplet has no `contained_in_file` of its own, so "may I write this link?"
 * is undefined until it is derived from the endpoints — exactly as visibility
 * is. The rule, symmetric with the read side:
 *
 *   A link may be written iff EVERY endpoint that has a `contained_in_file`
 *   sits in a file the principal may write.
 *
 * Two rules have no read-side equivalent:
 *
 *   - PROTECTED_VERBS (has_role, sandra_allow_access, sandra_allow_write) are
 *     the ACL itself. Writing one is granting a permission, so it takes the
 *     write wildcard. Without this a principal with any write grant can post
 *     `me --sandra_allow_access--> secretFile` and promote itself.
 *
 *   - A link whose endpoints carry NO file at all is pure vocabulary, shared by
 *     the whole datagraph and owned by nobody. Minting those is an admin act
 *     too; otherwise any writer can pollute the shared dictionary. Note this
 *     only bites when NEITHER endpoint has a file — tagging an entity with a
 *     bare concept is an ordinary write, gated by the entity's file.
 *
 * Entities are simpler — an entity HAS a file, so writing one is a plain
 * canWrite() on it. Two acts sit above files and take the wildcard: creating a
 * factory (it defines a new file, i.e. a new ACL unit) and, per the rule above,
 * linking two bare concepts. Minting a lone concept sits in between: harmless
 * on its own and needed by any annotating agent, so it asks only for some write
 * grant — a read-only principal cannot grow the dictionary.
 *
 * Denials throw, unlike the read side which stays silent. A caller that is told
 * "no" already knows it asked; the danger of a silent write is believing it
 * landed.
 */
final class WriteGuard
{
    private function __construct(
        private readonly System $system,
        private readonly AccessContext $access,
        private readonly int $cifId,
    ) {
    }

    /**
     * Null when no principal is acting — the legacy unrestricted path. A
     * `writeAll` principal still gets a guard: it passes every file check but
     * must remain subject to nothing else, and returning null here would only
     * duplicate that logic at each call site.
     */
    public static function forAccess(System $system, ?AccessContext $access): ?self
    {
        if ($access === null) {
            return null;
        }

        $cifId = $system->systemConcept->get('contained_in_file', null, false);

        return new self($system, $access, is_numeric($cifId) ? (int) $cifId : 0);
    }

    /** @throws AccessDeniedException */
    public function assertCanLink(int $subject, int $verb, int $target): void
    {
        if ($verb === $this->cifId && $this->cifId > 0) {
            $this->assertCanAttachFacet($subject, $target);
            return;
        }

        if ($this->isProtectedVerb($verb) && !$this->access->isAdmin()) {
            throw new AccessDeniedException(
                'Writing an ACL triplet (has_role / sandra_allow_access / sandra_allow_write) requires the write wildcard.'
            );
        }

        $files = $this->filesOf([$subject, $target]);

        foreach ([$subject => 'subject', $target => 'target'] as $endpoint => $role) {
            foreach ($files[$endpoint] ?? [] as $fileId) {
                if (!$this->access->canWrite($fileId)) {
                    throw new AccessDeniedException(
                        "No write grant on the file containing the $role of this link."
                    );
                }
            }
        }

        if (($files[$subject] ?? []) === [] && ($files[$target] ?? []) === [] && !$this->access->isAdmin()) {
            throw new AccessDeniedException(
                'Linking two bare concepts edits the shared vocabulary and requires the write wildcard.'
            );
        }
    }

    /**
     * Resolved by shortname, never by ids captured at construction: a verb
     * concept that does not exist yet would otherwise be absent from the list,
     * and creating it first would be enough to slip past this rule.
     */
    private function isProtectedVerb(int $verbId): bool
    {
        $shortname = $this->system->systemConcept->getShortname($verbId);

        return $shortname !== null && in_array($shortname, AclResolver::PROTECTED_VERBS, true);
    }

    public function mayLink(int $subject, int $verb, int $target): bool
    {
        try {
            $this->assertCanLink($subject, $verb, $target);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    /**
     * Guard for DatabaseAdapter::rawCreateTriplet($updateOnExistingLK = 1),
     * whose upsert matches on (subject, verb) and RETARGETS the row it finds.
     * That is the intended semantics — one link per (subject, verb) — but it
     * means the caller overwrites a target it may never have seen. Check the
     * link as it stands before letting the new one replace it.
     *
     * @throws AccessDeniedException
     */
    public function assertCanRetarget(int $subject, int $verb): void
    {
        $sql = "SELECT idConceptTarget FROM {$this->system->linkTable}
                WHERE idConceptStart = :subject AND idConceptLink = :verb AND flag != :deletedFlag";

        $rows = QueryExecutor::fetchAll($this->system->getConnection(), $sql, [
            ':subject' => [$subject, PDO::PARAM_INT],
            ':verb' => [$verb, PDO::PARAM_INT],
            ':deletedFlag' => [(int) $this->system->deletedUNID, PDO::PARAM_INT],
        ]) ?? [];

        if ($rows === []) {
            return;
        }

        $currentTargets = array_map(static fn (array $r): int => (int) $r['idConceptTarget'], $rows);
        $files = $this->filesOf($currentTargets);

        foreach ($currentTargets as $target) {
            foreach ($files[$target] ?? [] as $fileId) {
                if (!$this->access->canWrite($fileId)) {
                    throw new AccessDeniedException(
                        'This (subject, verb) already points at a target in a file you may not write; '
                        . 'retargeting it would overwrite a link you cannot see.'
                    );
                }
            }
        }
    }

    /**
     * Attaching (or retracting) a facet — a `contained_in_file` link — is not
     * the generic link write, and must not be judged like one.
     *
     * The generic rule asks that every endpoint's files be writable. Applied
     * here it would forbid the whole point of facets: annotating a PUBLIC
     * concept privately. Alice may write her own file and may read the public
     * blockchain address, but she will never be able to write
     * `blockchainAddressFile` — under the generic rule she could never attach
     * her private label to it.
     *
     * The rule that fits:
     *
     *   write the DESTINATION file, and be able to READ the subject
     *   (or the subject is bare vocabulary, i.e. a brand-new entity).
     *
     * The read clause is not cosmetic. Without it the endpoint rule becomes an
     * escalation: a principal who guesses the id of a concept it cannot see
     * could file that concept under its own file, make it visible to itself,
     * and so reveal the links touching it.
     *
     * Known and accepted: a principal holding write on a WIDELY READABLE file
     * can publish into it a concept it is allowed to read. That is inherent to
     * holding such a grant — it could equally retype the data by hand — and in
     * the intended deployment a user writes only their own private file.
     *
     * @throws AccessDeniedException
     */
    public function assertCanAttachFacet(int $subject, int $file): void
    {
        if (!$this->access->canWrite($file)) {
            throw new AccessDeniedException('No write grant on the file this facet would be filed under.');
        }

        $visibility = TripletVisibility::forAccess($this->system, $this->access);
        if ($visibility === null) {
            return; // readAll: nothing to hide from this principal
        }

        if ($visibility->visibleConcepts([$subject]) === []) {
            throw new AccessDeniedException(
                'Cannot file a concept you cannot read; doing so would reveal it to yourself.'
            );
        }
    }

    /**
     * Writing ONE facet of an entity — its refs, its storage. The unit is the
     * (entity, file) pair, which every tool already holds: it reaches the
     * entity through a factory, and a factory is exactly one facet.
     *
     * `assertCanWriteEntity()` stays for callers acting on the whole concept,
     * but must not be used for a ref update: it requires write on EVERY file,
     * so one HR facet would freeze a colleague out of their own `slack_id`.
     *
     * @throws AccessDeniedException
     */
    public function assertCanWriteFacet(int $entityId, string $fileShortname): void
    {
        $this->assertCanCreateInFile($fileShortname);
    }

    /**
     * Soft-deleting a link, or rewriting the storage hanging off it, is a write
     * on that link — same endpoint rule as creating it. Without this, a caller
     * could delete a link it was never allowed to see.
     *
     * @throws AccessDeniedException
     */
    public function assertCanTouchLink(int $linkId): void
    {
        $sql = "SELECT idConceptStart, idConceptLink, idConceptTarget FROM {$this->system->linkTable}
                WHERE id = :linkId";

        $rows = QueryExecutor::fetchAll($this->system->getConnection(), $sql, [
            ':linkId' => [$linkId, PDO::PARAM_INT],
        ]) ?? [];

        if ($rows === []) {
            // Unknown link: let the caller raise its own "not found".
            return;
        }

        $this->assertCanLink(
            (int) $rows[0]['idConceptStart'],
            (int) $rows[0]['idConceptLink'],
            (int) $rows[0]['idConceptTarget']
        );
    }

    /**
     * Writing an existing entity — refs, storage, deletion. The entity carries
     * its own file, so no derivation is needed.
     *
     * @throws AccessDeniedException
     */
    public function assertCanWriteEntity(int $entityId): void
    {
        $files = $this->filesOf([$entityId])[$entityId] ?? [];

        if ($files === []) {
            if (!$this->access->isAdmin()) {
                throw new AccessDeniedException(
                    'This concept is not contained in any file; editing it edits the shared vocabulary.'
                );
            }
            return;
        }

        foreach ($files as $fileId) {
            if (!$this->access->canWrite($fileId)) {
                throw new AccessDeniedException('No write grant on the file containing this entity.');
            }
        }
    }

    /**
     * Creating an entity in a factory: the grant is on the factory's
     * contained_in_file, named here by shortname because that is what a factory
     * carries.
     *
     * @throws AccessDeniedException
     */
    public function assertCanCreateInFile(string $fileShortname): void
    {
        $this->assertFileDeclared($fileShortname);

        if ($this->access->writeAll) {
            return;
        }

        $fileId = $this->system->systemConcept->get($fileShortname, null, false);
        if (!is_numeric($fileId) || !$this->access->canWrite((int) $fileId)) {
            throw new AccessDeniedException("No write grant on file '$fileShortname'.");
        }
    }

    /**
     * A file is declared, never a side effect.
     *
     * Naming an unknown `contained_in_file` used to mint the concept on the
     * spot — i.e. create a permission boundary by accident, with nobody
     * deciding whether it should be visible. Files now come from
     * FileManager::create() (`sandra_create_file`).
     *
     * A file already holding entities counts as declared, so every graph that
     * predates the rule keeps working: what is refused is a file that exists
     * NOWHERE yet, which is exactly the accidental case.
     *
     * @throws AccessDeniedException
     */
    public function assertFileDeclared(string $fileShortname): void
    {
        if ((new FileManager($this->system))->isDeclared($fileShortname)) {
            return;
        }

        throw new AccessDeniedException(
            "File '$fileShortname' does not exist. A file is a permission boundary and is created "
            . 'explicitly with sandra_create_file, never as a side effect of writing an entity.'
        );
    }

    /**
     * Minting a bare concept. Deliberately the mildest rule of the set: a lone
     * concept carries no data and no link, and an annotating agent needs to
     * create its own tags. It still takes SOME write grant, so a read-only
     * principal cannot inflate the shared dictionary.
     *
     * @throws AccessDeniedException
     */
    public function assertCanMintConcept(): void
    {
        if (!$this->access->canWriteAnything()) {
            throw new AccessDeniedException('A read-only principal cannot create concepts.');
        }
    }

    /**
     * Creating a factory creates its contained_in_file — a new ACL unit that no
     * existing grant covers. Only the wildcard may widen the graph's structure.
     *
     * @throws AccessDeniedException
     */
    public function assertCanCreateFactory(string $fileShortname): void
    {
        $this->assertFileDeclared($fileShortname);

        if ($this->access->isAdmin()) {
            return;
        }

        // Only a NEW file is a new ACL unit. Declaring another is_a inside a
        // file the principal already writes creates no permission: it is the
        // ordinary write that lets a user keep several kinds of thing — notes,
        // labels, wallets — in their own file.
        $fileId = $this->system->systemConcept->get($fileShortname, null, false);
        if (is_numeric($fileId) && $this->access->canWrite((int) $fileId)) {
            return;
        }

        throw new AccessDeniedException(
            "Creating factory file '$fileShortname' defines a new ACL unit and requires the write wildcard."
        );
    }

    /**
     * contained_in_file targets of each endpoint, in one indexed query.
     *
     * @param  int[] $endpointIds
     * @return array<int, int[]> endpoint id => file concept ids (missing = bare concept)
     */
    private function filesOf(array $endpointIds): array
    {
        $endpointIds = array_values(array_unique(array_filter(array_map('intval', $endpointIds))));
        if ($endpointIds === [] || $this->cifId <= 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($endpointIds), '?'));
        $sql = "SELECT idConceptStart, idConceptTarget FROM {$this->system->linkTable}
                WHERE idConceptLink = ? AND idConceptStart IN ($placeholders) AND flag != ?";

        $bound = [];
        foreach (array_merge([$this->cifId], $endpointIds, [(int) $this->system->deletedUNID]) as $i => $value) {
            $bound[$i + 1] = $value;
        }

        $stmt = QueryExecutor::execute($this->system->getConnection(), $sql, $bound);
        if ($stmt === null) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['idConceptStart']][] = (int) $row['idConceptTarget'];
        }

        return $out;
    }
}
