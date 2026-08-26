<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;

/**
 * MCP token → principal wiring: a request scoped via setRequestPrincipal()
 * (set by HttpTransport from the token's principal_concept_id) must be
 * default-deny — ACL-aware tools filtered, every other tool blocked.
 */
class McpAclTest extends SandraTestCase
{
    private McpServer $mcp;
    private int $alicePrincipal;

    protected function setUp(): void
    {
        parent::setUp();

        $employees = new EntityFactory('employee', 'employee_file', $this->system);
        $employees->createNew(['name' => 'Marie', 'salary' => '90000']);
        $employees->createNew(['name' => 'Jean', 'salary' => '70000']);
        $secrets = new EntityFactory('secret', 'secret_file', $this->system);
        $secrets->createNew(['name' => 'Merger plan']);

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $alice = $keys->createNew(['name' => 'alice-key']);
        $this->alicePrincipal = (int) $alice->subjectConcept->idConcept;

        // alice --has_role--> hr ; hr --sandra_allow_access--> employee_file
        $sc = $this->system->systemConcept;
        DatabaseAdapter::rawCreateTriplet($this->alicePrincipal, (int) $sc->get(AclResolver::HAS_ROLE), (int) $sc->get('hr'), $this->system);
        DatabaseAdapter::rawCreateTriplet((int) $sc->get('hr'), (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('employee_file'), $this->system);

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('employee', $employees, []);
        $this->mcp->register('secret', $secrets, []);
        $this->mcp->register('api_key', $keys, []);
        $this->mcp->boot();
    }

    private function callTool(string $name, array $arguments): array
    {
        $response = $this->mcp->dispatchMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        $content = $response['result']['content'][0]['text'] ?? '';
        return [
            'isError' => (bool) ($response['result']['isError'] ?? false),
            'text' => $content,
            'json' => json_decode($content, true),
        ];
    }

    public function testUnscopedRequestsStayUnrestricted(): void
    {
        $out = $this->callTool('sandra_ql', ['query' => 'MATCH secret IN secret_file']);
        $this->assertFalse($out['isError']);
        $this->assertSame(1, $out['json']['count']);
    }

    public function testScopedSandraQlIsDefaultDeny(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        try {
            $granted = $this->callTool('sandra_ql', ['query' => 'MATCH employee IN employee_file ORDER BY name']);
            $this->assertFalse($granted['isError']);
            $this->assertSame(2, $granted['json']['count']);
            $this->assertSame('Jean', $granted['json']['entities'][0]['refs']['name']);

            $denied = $this->callTool('sandra_ql', ['query' => 'MATCH secret IN secret_file']);
            $this->assertFalse($denied['isError']);
            $this->assertSame(0, $denied['json']['count']);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    public function testScopedQueryEntitiesGoesThroughAcl(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        try {
            $granted = $this->callTool('sandra_query_entities', [
                'factory' => 'employee',
                'filters' => [['ref' => 'salary', 'op' => '>', 'value' => 80000]],
            ]);
            $this->assertFalse($granted['isError']);
            $this->assertSame(1, $granted['json']['count']);
            $this->assertSame('Marie', $granted['json']['entities'][0]['refs']['name']);

            $denied = $this->callTool('sandra_query_entities', [
                'factory' => 'secret',
                'filters' => [['ref' => 'name', 'op' => 'LIKE', 'value' => '%']],
            ]);
            $this->assertFalse($denied['isError']);
            $this->assertSame(0, $denied['json']['count']);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    public function testScopedListFactoriesIsFiltered(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        try {
            $out = $this->callTool('sandra_list_factories', []);
            $this->assertFalse($out['isError']);
            $files = array_column($out['json'], 'entityContainedIn');
            $this->assertContains('employee_file', $files);
            $this->assertNotContains('secret_file', $files);
            $this->assertNotContains('keys_file', $files);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    public function testNonAclAwareToolsAreBlockedForPrincipals(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        try {
            // sandra_embed_all is deliberately left out of ACL_AWARE_TOOLS: a
            // bulk job over the whole graph that calls a paid API is not
            // something a scoped agent should reach.
            $out = $this->callTool('sandra_embed_all', ['limit' => 1]);
            $this->assertTrue($out['isError'], 'sandra_embed_all should be blocked for principal-scoped tokens');
            $this->assertStringContainsString('not ACL-aware', $out['text']);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    /**
     * Regression guard: a tool is either ACL-aware or explicitly refused. A new
     * tool that is neither would silently serve principals unfiltered data.
     */
    public function testEveryToolIsEitherAclAwareOrBlocked(): void
    {
        $unclassified = [];
        foreach ($this->mcp->getToolRegistry()->names() as $name) {
            $tool = $this->mcp->getToolRegistry()->get($name);
            $aclAware = $tool instanceof \SandraCore\Mcp\AclAwareToolInterface;
            $listed = in_array($name, McpServer::ACL_AWARE_TOOLS, true);

            if ($aclAware !== $listed) {
                $unclassified[] = $name . ($aclAware ? ' (implements the interface but is not listed)' : ' (listed but does not implement the interface)');
            }
        }

        $this->assertSame([], $unclassified, 'ACL_AWARE_TOOLS and AclAwareToolInterface must agree');
    }

    /**
     * A host application registers its own tools after boot(). Those must be
     * declarable as ACL-aware — otherwise embedding the server means every
     * principal is refused every tool the host actually cares about — and the
     * declaration must be refused for a tool that cannot receive an
     * AccessContext, since such a tool could only ever pretend to filter.
     */
    public function testHostToolsCanBeDeclaredAclAwareOnlyWithTheInterface(): void
    {
        $this->mcp->registerTool($this->hostTool('host_blind', false));
        $this->mcp->registerTool($this->hostTool('host_scoped', true));

        try {
            $this->mcp->declareAclAware('host_blind');
            $this->fail('A tool without AclAwareToolInterface must not be declarable.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('does not implement', $e->getMessage());
        }

        try {
            $this->mcp->declareAclAware('never_registered');
            $this->fail('An unregistered tool must not be declarable.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unknown tool', $e->getMessage());
        }

        $this->mcp->declareAclAware('host_scoped');
        $this->mcp->setRequestPrincipal($this->alicePrincipal);

        $blind = $this->callTool('host_blind', []);
        $this->assertTrue($blind['isError'], 'An undeclared host tool stays blocked for a principal.');
        $this->assertStringContainsString('not ACL-aware', $blind['text']);

        $scoped = $this->callTool('host_scoped', []);
        $this->assertFalse($scoped['isError']);
        // The point of the interface: the tool was handed the principal's access.
        $this->assertSame('employee_file', $scoped['json']);
    }

    /** A stand-in for a tool an embedding application registers itself. */
    private function hostTool(string $name, bool $aclAware): \SandraCore\Mcp\McpToolInterface
    {
        if (!$aclAware) {
            return new class($name) implements \SandraCore\Mcp\McpToolInterface {
                public function __construct(private string $n) {}
                public function name(): string { return $this->n; }
                public function description(): string { return 'stub'; }
                public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }
                public function execute(array $args): mixed { return 'ran'; }
            };
        }

        return new class($name, $this->system) implements \SandraCore\Mcp\McpToolInterface, \SandraCore\Mcp\AclAwareToolInterface {
            private ?\SandraCore\Acl\AccessContext $access = null;
            public function __construct(private string $n, private \SandraCore\System $system) {}
            public function name(): string { return $this->n; }
            public function description(): string { return 'stub'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }
            public function setAccess(?\SandraCore\Acl\AccessContext $access): void { $this->access = $access; }
            public function execute(array $args): mixed
            {
                // Report what it was granted, by name, so the test sees the
                // context actually arrived rather than merely that it ran.
                $names = [];
                foreach (array_keys($this->access?->allowedRead ?? []) as $fileId) {
                    $names[] = (string) $this->system->systemConcept->getShortname((int) $fileId);
                }
                sort($names);

                return implode(',', $names);
            }
        };
    }

    public function testPrincipalResetRestoresRoot(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        $this->mcp->setRequestPrincipal(null);
        $out = $this->callTool('sandra_ql', ['query' => 'MATCH secret IN secret_file']);
        $this->assertSame(1, $out['json']['count']);
    }
}
