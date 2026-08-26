<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\Acl\WriteGuard;
use SandraCore\DatabaseAdapter;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\Mcp\GroupManager;
use SandraCore\Mcp\UserProvisioner;

/**
 * Groups: a shared file, membership through `has_role`, and the one deliberate
 * ACL bypass in the system — tested for what it REFUSES as much as for what it
 * allows.
 */
class McpGroupTest extends SandraTestCase
{
    private GroupManager $groups;
    private int $alice;
    private int $bob;
    private int $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $provisioner = new UserProvisioner($this->system);
        $this->alice = $provisioner->provision('alice');
        $this->bob = $provisioner->provision('bob');
        $this->carol = $provisioner->provision('carol');
        $this->groups = new GroupManager($this->system);
    }

    private function canRead(int $principal, string $file): bool
    {
        return AclResolver::resolve($this->system, $principal)
            ->canRead((int) $this->system->systemConcept->get($file));
    }

    public function testTheCreatorOwnsTheGroupAndIsItsFirstMember(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');

        $this->assertSame($this->alice, $this->groups->ownerOf($group));
        $this->assertTrue($this->groups->isMember($this->alice, $group));
        $this->assertTrue(
            $this->canRead($this->alice, GroupManager::groupFile($group)),
            'membership opens the shared file through the role closure'
        );
    }

    public function testAddingAMemberOpensTheSharedFileToThem(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $file = GroupManager::groupFile($group);

        $this->assertFalse($this->canRead($this->bob, $file), 'not a member yet');
        $this->groups->addMember($this->alice, $group, $this->bob);
        $this->assertTrue($this->canRead($this->bob, $file));
        $this->assertFalse($this->canRead($this->carol, $file), 'and only the member added');
    }

    public function testRemovingAMemberClosesItAgain(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $this->groups->addMember($this->alice, $group, $this->bob);

        $this->groups->removeMember($this->alice, $group, $this->bob);
        $this->assertFalse($this->canRead($this->bob, GroupManager::groupFile($group)));
        $this->assertFalse($this->groups->isMember($this->bob, $group));
    }

    // ------------------------------------------------------------ refusals

    public function testANonOwnerCannotChangeMembership(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $this->groups->addMember($this->alice, $group, $this->bob);

        $this->expectException(AccessDeniedException::class);
        $this->groups->addMember($this->bob, $group, $this->carol);
    }

    public function testTheOwnerCannotBeRemoved(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');

        $this->expectException(AccessDeniedException::class);
        $this->groups->removeMember($this->alice, $group, $this->alice);
    }

    public function testOnlyAProvisionedUserCanBeInvited(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $stranger = (int) $this->system->systemConcept->get('some_random_concept');

        $this->expectException(\InvalidArgumentException::class);
        $this->groups->addMember($this->alice, $group, $stranger);
    }

    /**
     * The clause that keeps the bypass from becoming an escalation: an admin
     * granting a user-owned group access to another file would otherwise let
     * that owner hand the file to anyone by adding them.
     */
    public function testAGroupReachingBeyondItsOwnFileBecomesAdminManaged(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');

        $sc = $this->system->systemConcept;
        DatabaseAdapter::rawCreateTriplet(
            $group, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('secrets_file'), $this->system
        );

        $this->expectException(AccessDeniedException::class);
        $this->groups->addMember($this->alice, $group, $this->bob);
    }

    /**
     * The bypass is a hole exactly one triplet wide: the ordinary write path
     * still refuses `has_role` to a non-admin.
     */
    public function testTheNormalWritePathStillRefusesHasRole(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $guard = WriteGuard::forAccess($this->system, AclResolver::resolve($this->system, $this->alice));

        $this->assertFalse(
            $guard->mayLink($this->carol, (int) $this->system->systemConcept->get(AclResolver::HAS_ROLE), $group),
            'only GroupManager may write it, and only for an owner'
        );
    }

    /** Through the MCP surface, as the principal — the way it will be used. */
    public function testTheToolActsAsTheCallingPrincipal(): void
    {
        $mcp = new \SandraCore\Mcp\McpServer($this->system);
        $mcp->boot();

        $call = function (array $args, ?int $principal) use ($mcp): array {
            if ($principal !== null) {
                $mcp->setRequestPrincipal($principal);
            }
            try {
                $r = $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                    'params' => ['name' => 'sandra_group', 'arguments' => $args]]);
            } finally {
                $mcp->setRequestPrincipal(null);
            }
            $text = $r['result']['content'][0]['text'] ?? '';

            return ['isError' => (bool) ($r['result']['isError'] ?? false), 'json' => json_decode($text, true), 'text' => $text];
        };

        $created = $call(['action' => 'create', 'label' => 'watchers'], $this->alice);
        $this->assertFalse($created['isError'], $created['text']);
        $group = (int) $created['json']['group'];
        $this->assertSame($this->alice, $this->groups->ownerOf($group));

        // Bob is not the owner: the tool must refuse him.
        $this->assertTrue($call(['action' => 'add_member', 'group' => $group, 'member' => $this->carol], $this->bob)['isError']);

        $ok = $call(['action' => 'add_member', 'group' => $group, 'member' => $this->bob], $this->alice);
        $this->assertFalse($ok['isError'], $ok['text']);
        $this->assertTrue($this->groups->isMember($this->bob, $group));

        // A root token has no identity to own a group with.
        $this->assertTrue($call(['action' => 'create', 'label' => 'rootless'], null)['isError']);

        // REGRESSION: creating a group hands the owner a brand-new file. The
        // resolved AccessContext is cached per principal, so without busting it
        // the owner was refused the very file they had just earned, until the
        // TTL expired. Found end-to-end, not by the unit tests.
        $mcp->setRequestPrincipal($this->alice);
        try {
            $write = $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
                'params' => ['name' => 'sandra_create_entity', 'arguments' => [
                    'factory' => 'note',
                    'contained_in_file' => \SandraCore\Mcp\GroupManager::groupFile($group),
                    'refs' => ['body' => 'shared right away'],
                ]]]);
        } finally {
            $mcp->setRequestPrincipal(null);
        }

        $this->assertFalse(
            (bool) ($write['result']['isError'] ?? false),
            'the owner must be able to write the group file immediately: '
            . ($write['result']['content'][0]['text'] ?? '')
        );
    }

    public function testMembersShareWhatIsFiledUnderTheGroup(): void
    {
        $group = $this->groups->create($this->alice, 'EDS analysts');
        $this->groups->addMember($this->alice, $group, $this->bob);
        $file = GroupManager::groupFile($group);

        // A public address, given a facet in the group's file.
        $addresses = new \SandraCore\EntityFactory('btcAddress', 'blockchainAddressFile', $this->system);
        $wallet = (int) $addresses->createNew(['address' => '1PWqaoM'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $facet = (int) DatabaseAdapter::rawCreateTriplet(
            $wallet, (int) $sc->get('contained_in_file'), (int) $sc->get($file), $this->system
        );
        DatabaseAdapter::rawCreateReference($facet, (int) $sc->get('label'), 'shared watchlist', $this->system);

        foreach ([$this->alice, $this->bob] as $member) {
            $this->assertTrue($this->canRead($member, $file));
        }
        $this->assertFalse($this->canRead($this->carol, $file));
    }
}
