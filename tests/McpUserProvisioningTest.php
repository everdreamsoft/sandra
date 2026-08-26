<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\UserProvisioner;

/**
 * Provisioning a principal: a `user` entity, a file of its own, and the grants
 * that make that file private to it.
 */
class McpUserProvisioningTest extends SandraTestCase
{
    private function provisioner(): UserProvisioner
    {
        return new UserProvisioner($this->system);
    }

    public function testAFreshHandleGetsAPrincipalAFileAndItsGrants(): void
    {
        $alice = $this->provisioner()->provision('alice');
        $this->assertGreaterThan(0, $alice);

        $access = AclResolver::resolve($this->system, $alice);
        $fileId = (int) $this->system->systemConcept->get(UserProvisioner::personalFile($alice));

        $this->assertTrue($access->canRead($fileId), 'she reads her own file');
        $this->assertTrue($access->canWrite($fileId), 'and writes it');
        $this->assertFalse($access->isAdmin(), 'without any wildcard');
    }

    public function testProvisioningIsIdempotent(): void
    {
        $first = $this->provisioner()->provision('alice');
        $second = $this->provisioner()->provision('alice');

        $this->assertSame($first, $second, 'the same handle must not mint a second principal');

        $users = new EntityFactory(UserProvisioner::USER_ISA, UserProvisioner::USER_FILE, $this->system);
        $users->populateLocal(50);
        $this->assertCount(1, $users->getEntities());
    }

    public function testOneUserCannotSeeAnothersFile(): void
    {
        $alice = $this->provisioner()->provision('alice');
        $bob = $this->provisioner()->provision('bob');

        $bobFile = (int) $this->system->systemConcept->get(UserProvisioner::personalFile($bob));
        $aliceAccess = AclResolver::resolve($this->system, $alice);

        $this->assertFalse($aliceAccess->canRead($bobFile));
        $this->assertFalse($aliceAccess->canWrite($bobFile));
    }

    public function testTheFileIsKeyedOnTheIdNotTheHandle(): void
    {
        $alice = $this->provisioner()->provision('alice');
        $this->assertSame("user_{$alice}_file", UserProvisioner::personalFile($alice));
    }

    /** The point of the whole thing, end to end through the MCP surface. */
    public function testEachUserOnlySeesWhatIsInTheirOwnFile(): void
    {
        $alice = $this->provisioner()->provision('alice');
        $bob = $this->provisioner()->provision('bob');
        $aliceFile = UserProvisioner::personalFile($alice);
        $bobFile = UserProvisioner::personalFile($bob);

        // A public address both of them track, already in the graph.
        $addresses = new EntityFactory('btcAddress', 'blockchainAddressFile', $this->system);
        $wallet = (int) $addresses->createNew(['address' => '1PWqaoM'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        foreach ([[$alice, $aliceFile, 'cold wallet'], [$bob, $bobFile, 'whale']] as [$who, $file, $label]) {
            $link = (int) DatabaseAdapter::rawCreateTriplet(
                $wallet, (int) $sc->get('contained_in_file'), (int) $sc->get($file), $this->system
            );
            DatabaseAdapter::rawCreateReference($link, (int) $sc->get('label'), $label, $this->system);
            // both may also read the public file
            DatabaseAdapter::rawCreateTriplet(
                $who, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('blockchainAddressFile'), $this->system
            );
        }

        $mcp = new McpServer($this->system);
        $mcp->register('btcAddress', $addresses, []);
        $mcp->boot();

        // read each one's own facet through their own file
        $aliceQl = $this->ql($mcp, $alice, "MATCH btcAddress IN $aliceFile");
        $bobQl = $this->ql($mcp, $bob, "MATCH btcAddress IN $bobFile");
        $aliceOnBob = $this->ql($mcp, $alice, "MATCH btcAddress IN $bobFile");

        $this->assertSame('cold wallet', $aliceQl[0]['refs']['label'] ?? null);
        $this->assertSame('whale', $bobQl[0]['refs']['label'] ?? null);
        $this->assertSame([], $aliceOnBob, "alice must not read bob's file");
    }

    private function ql(McpServer $mcp, int $principal, string $query): array
    {
        $mcp->setRequestPrincipal($principal);
        try {
            $r = $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'sandra_ql', 'arguments' => ['query' => $query]]]);
        } finally {
            $mcp->setRequestPrincipal(null);
        }

        return json_decode($r['result']['content'][0]['text'] ?? '', true)['entities'] ?? [];
    }
}
