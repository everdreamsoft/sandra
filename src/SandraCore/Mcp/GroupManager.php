<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use PDO;
use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Groups: a file several users share, and the membership that opens it.
 *
 * A group is an entity that doubles as a ROLE. `AclResolver` closes over
 * `has_role` up to depth 3 and then collects the grants of the principal AND of
 * its roles, so:
 *
 *   group  --sandra_allow_access--> group_<id>_file
 *   group  --sandra_allow_write --> group_<id>_file
 *   member --has_role-----------> group
 *
 * is all it takes: joining is one triplet, and everything filed under the
 * group's file becomes readable and writable by every member. Nothing about the
 * ACL had to be extended for this — it is the role closure doing its job.
 *
 * The group's own record is filed twice: once in `groupFile`, the administrative
 * index, and once as a facet in its own file so that MEMBERS can read its label
 * without being able to enumerate every group in the graph.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ONE DELIBERATE ACL BYPASS IN THE SYSTEM.
 *
 * `has_role` is a PROTECTED_VERB: WriteGuard refuses it to anyone without the
 * write wildcard, and rightly so — writing it is granting a permission, and a
 * principal that could write it would grant itself anything. But an owner
 * adding a member to their own group is exactly that write, performed by a
 * non-admin.
 *
 * So membership changes go through rawCreateTriplet WITHOUT a guard, behind a
 * check that is NARROWER than the one being bypassed:
 *   - the caller owns the group,
 *   - the target is an existing `user` entity,
 *   - the group holds no grant beyond its own file.
 *
 * That last clause is what keeps the bypass from becoming an escalation. If an
 * admin ever granted a user-owned group access to another file, the owner could
 * hand that file to anyone by adding them. Rather than trust nobody does that,
 * membership is refused outright on any group whose reach exceeds its own file.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class GroupManager
{
    public const GROUP_ISA = 'group';
    public const GROUP_INDEX_FILE = 'groupFile';
    public const REF_LABEL = 'label';
    public const VERB_OWNER = 'groupOwner';

    public function __construct(private readonly System $system)
    {
    }

    /** The file a group shares with its members. */
    public static function groupFile(int $groupId): string
    {
        return 'group_' . $groupId . '_file';
    }

    /**
     * Create a group owned by the caller, who becomes its first member.
     *
     * @return int the group concept id — also the role id used by `has_role`
     */
    public function create(int $ownerPrincipal, string $label): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('A group needs a label.');
        }
        $this->assertIsUser($ownerPrincipal, 'The caller');

        $groups = new EntityFactory(self::GROUP_ISA, self::GROUP_INDEX_FILE, $this->system);
        $group = $groups->createNew([self::REF_LABEL => $label, 'createdAt' => (string) time()]);
        $groupId = (int) $group->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $fileId = (int) $sc->get(self::groupFile($groupId));

        // The group carries the grants; members inherit them through has_role.
        foreach ([AclResolver::ALLOW_ACCESS, AclResolver::ALLOW_WRITE] as $verb) {
            DatabaseAdapter::rawCreateTriplet($groupId, (int) $sc->get($verb), $fileId, $this->system);
        }

        DatabaseAdapter::rawCreateTriplet($groupId, (int) $sc->get(self::VERB_OWNER), $ownerPrincipal, $this->system);

        // A facet of the group in its own file: members read the label without
        // gaining the right to enumerate groupFile.
        $facet = (int) DatabaseAdapter::rawCreateTriplet(
            $groupId, (int) $sc->get('contained_in_file'), $fileId, $this->system
        );
        DatabaseAdapter::rawCreateReference($facet, (int) $sc->get(self::REF_LABEL), $label, $this->system);

        $this->link($ownerPrincipal, $groupId);

        return $groupId;
    }

    public function addMember(int $caller, int $groupId, int $member): void
    {
        $this->assertMayManage($caller, $groupId);
        $this->assertIsUser($member, 'The invitee');
        $this->link($member, $groupId);
    }

    public function removeMember(int $caller, int $groupId, int $member): void
    {
        $this->assertMayManage($caller, $groupId);

        if ($member === $this->ownerOf($groupId)) {
            throw new AccessDeniedException('The owner cannot be removed from their own group.');
        }

        // Soft-delete, like DeleteTripletTool: the flag is what every read
        // filters on, and there is no raw delete helper on DatabaseAdapter.
        $sc = $this->system->systemConcept;
        QueryExecutor::execute(
            $this->system->getConnection(),
            "UPDATE `{$this->system->linkTable}` SET flag = :deleted
             WHERE idConceptStart = :member AND idConceptLink = :verb AND idConceptTarget = :group",
            [
                ':deleted' => [(int) $this->system->deletedUNID, PDO::PARAM_INT],
                ':member' => [$member, PDO::PARAM_INT],
                ':verb' => [(int) $sc->get(AclResolver::HAS_ROLE), PDO::PARAM_INT],
                ':group' => [$groupId, PDO::PARAM_INT],
            ]
        );
    }

    public function ownerOf(int $groupId): ?int
    {
        $sc = $this->system->systemConcept;
        $rows = $this->targets($groupId, (int) $sc->get(self::VERB_OWNER));

        return $rows === [] ? null : $rows[0];
    }

    public function isMember(int $principal, int $groupId): bool
    {
        $sc = $this->system->systemConcept;

        return in_array($groupId, $this->targets($principal, (int) $sc->get(AclResolver::HAS_ROLE)), true);
    }

    /**
     * The narrow check standing in for the guard that is being bypassed.
     *
     * @throws AccessDeniedException
     */
    private function assertMayManage(int $caller, int $groupId): void
    {
        if ($this->ownerOf($groupId) !== $caller) {
            throw new AccessDeniedException('Only the owner of a group may change its membership.');
        }

        $sc = $this->system->systemConcept;
        $own = (int) $sc->get(self::groupFile($groupId));

        foreach ([AclResolver::ALLOW_ACCESS, AclResolver::ALLOW_WRITE] as $verb) {
            foreach ($this->targets($groupId, (int) $sc->get($verb)) as $granted) {
                if ($granted !== $own) {
                    throw new AccessDeniedException(
                        'This group has been granted a file beyond its own; its membership is '
                        . 'administrator-managed, otherwise its owner could hand that file to anyone.'
                    );
                }
            }
        }
    }

    /** @throws \InvalidArgumentException */
    private function assertIsUser(int $principal, string $who): void
    {
        $sc = $this->system->systemConcept;
        $isa = $this->targets($principal, (int) $sc->get('is_a'));

        if (!in_array((int) $sc->get(UserProvisioner::USER_ISA), $isa, true)) {
            throw new \InvalidArgumentException("$who is not a provisioned user.");
        }
    }

    /**
     * The bypass itself, in one place: `has_role` is written with NO WriteGuard,
     * because the guard would refuse it to anyone but an admin. Every caller
     * reaches it through assertMayManage().
     */
    private function link(int $member, int $groupId): void
    {
        DatabaseAdapter::rawCreateTriplet(
            $member,
            (int) $this->system->systemConcept->get(AclResolver::HAS_ROLE),
            $groupId,
            $this->system
        );
    }

    /** @return int[] active targets of (subject, verb) */
    private function targets(int $subject, int $verbId): array
    {
        $sql = "SELECT idConceptTarget FROM `{$this->system->linkTable}`
                WHERE idConceptStart = :subject AND idConceptLink = :verb AND flag != :deleted";

        $rows = QueryExecutor::fetchAll($this->system->getConnection(), $sql, [
            ':subject' => [$subject, PDO::PARAM_INT],
            ':verb' => [$verbId, PDO::PARAM_INT],
            ':deleted' => [(int) $this->system->deletedUNID, PDO::PARAM_INT],
        ]) ?? [];

        return array_map(static fn (array $r): int => (int) $r['idConceptTarget'], $rows);
    }
}
