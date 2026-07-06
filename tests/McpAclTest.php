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
            foreach (['sandra_list_entities', 'sandra_get_triplets', 'sandra_create_entity', 'sandra_search'] as $tool) {
                $out = $this->callTool($tool, ['factory' => 'employee', 'conceptId' => 1, 'refs' => ['name' => 'x']]);
                $this->assertTrue($out['isError'], "$tool should be blocked for principal-scoped tokens");
                $this->assertStringContainsString('not ACL-aware', $out['text'], $tool);
            }
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    public function testPrincipalResetRestoresRoot(): void
    {
        $this->mcp->setRequestPrincipal($this->alicePrincipal);
        $this->mcp->setRequestPrincipal(null);
        $out = $this->callTool('sandra_ql', ['query' => 'MATCH secret IN secret_file']);
        $this->assertSame(1, $out['json']['count']);
    }
}
