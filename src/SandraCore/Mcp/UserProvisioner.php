<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\System;

/**
 * Turns a handle into a graph principal: a `user` entity, a file of its own,
 * and the grants that make that file its private space.
 *
 * The unit of the ACL is the file, so "only this user (and an admin) sees it"
 * means: one file per user. Everything the user stores — tracked wallets,
 * notes, private labels — lives there, whatever its is_a. Admins reach it
 * through the `sandra_all_files` wildcard; nobody else has a grant on it.
 *
 * Idempotent by construction: the user is looked up by handle, and grants are
 * upserted on (subject, verb, target). Calling it twice for the same handle
 * yields the same principal and adds nothing.
 *
 * NOT race-safe on its own: two processes provisioning the same handle at the
 * same instant can both create a `user` entity, because the lookup and the
 * insert are separate statements. The caller settles it — TokenAuthService
 * stamps the token with a conditional UPDATE and adopts whichever principal
 * won, so the loser's entity is simply never referenced.
 */
final class UserProvisioner
{
    public const USER_ISA = 'user';
    public const USER_FILE = 'userFile';
    public const REF_HANDLE = 'handle';
    public const REF_PROVISIONED_AT = 'provisionedAt';

    public function __construct(private readonly System $system)
    {
    }

    /**
     * The file that belongs to a principal.
     *
     * Keyed on the concept id, not the handle: the id never changes, a handle
     * can be renamed, and a file rename would silently orphan every grant
     * pointing at the old concept.
     */
    public static function personalFile(int $principalId): string
    {
        return 'user_' . $principalId . '_file';
    }

    /**
     * Find or create the user behind a handle, then make sure it owns its file.
     *
     * @return int the principal concept id, to be stamped on the token
     */
    public function provision(string $handle): int
    {
        $handle = trim($handle);
        if ($handle === '') {
            throw new \InvalidArgumentException('Cannot provision a user without a handle.');
        }

        $users = new EntityFactory(self::USER_ISA, self::USER_FILE, $this->system);
        $user = $users->getOrCreateFromRef(self::REF_HANDLE, $handle);
        $principalId = (int) $user->subjectConcept->idConcept;

        if ($user->getReference(self::REF_PROVISIONED_AT) === null) {
            $users->update($user, [self::REF_PROVISIONED_AT => (string) time()]);
        }

        $this->grantOwnFile($principalId);

        return $principalId;
    }

    /**
     * Read AND write on its own file — in one go, deliberately.
     *
     * McpServer caches a resolved AccessContext for 30 s per principal. A
     * principal created now and granted a moment later would spend that window
     * seeing denials on a file it owns.
     */
    private function grantOwnFile(int $principalId): void
    {
        $sc = $this->system->systemConcept;
        $fileId = (int) $sc->get(self::personalFile($principalId));

        foreach ([AclResolver::ALLOW_ACCESS, AclResolver::ALLOW_WRITE] as $verb) {
            DatabaseAdapter::rawCreateTriplet($principalId, (int) $sc->get($verb), $fileId, $this->system);
        }
    }
}
