<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;

/**
 * Endpoint-derived triplet visibility.
 *
 * File grants hide ENTITIES; without this filter the LINKS survive, and a link
 * is often the secret — "this address belongs to that cluster" is disclosed by
 * the edge alone, whatever the cluster entity holds. The rule under test: a
 * triplet with an endpoint in an unreadable file does not exist for that
 * principal, on every path (details, counts, hops).
 *
 * Theme: on-chain attribution — addresses are public, who owns them is not.
 */
class McpTripletVisibilityTest extends SandraTestCase
{
    private McpServer $mcp;
    private int $principal;
    private int $publicAddressId;
    private int $taggedAddressId;
    private int $clusterId;

    protected function setUp(): void
    {
        parent::setUp();

        $addresses = new EntityFactory('address', 'address_file', $this->system);
        $public = $addresses->createNew(['address' => '1PWqaoM']);
        $tagged = $addresses->createNew(['address' => '1N4iYoft']);
        $this->publicAddressId = (int) $public->subjectConcept->idConcept;
        $this->taggedAddressId = (int) $tagged->subjectConcept->idConcept;

        $clusters = new EntityFactory('cluster', 'cluster_file', $this->system);
        $cluster = $clusters->createNew(['label' => 'exchange-delisted']);
        $this->clusterId = (int) $cluster->subjectConcept->idConcept;

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $this->principal = (int) $keys->createNew(['name' => 'analyst-key'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $cif = (int) $sc->get('contained_in_file');

        // The link that must disappear: its TARGET sits in an ungranted file.
        DatabaseAdapter::rawCreateTriplet($this->publicAddressId, (int) $sc->get('memberOf'), $this->clusterId, $this->system);
        // Vocabulary target (no contained_in_file) — must stay visible.
        DatabaseAdapter::rawCreateTriplet($this->publicAddressId, (int) $sc->get('hasTag'), (int) $sc->get('dispenser_operator'), $this->system);
        // A bare CONCEPT made private by giving it its own file: the link to it
        // must disappear exactly like a link to a private entity.
        $privateTag = (int) $sc->get('owner_eds');
        DatabaseAdapter::rawCreateTriplet($privateTag, $cif, (int) $sc->get('cluster_file'), $this->system);
        DatabaseAdapter::rawCreateTriplet($this->taggedAddressId, (int) $sc->get('hasTag'), $privateTag, $this->system);

        // The principal reads addresses, nothing else.
        DatabaseAdapter::rawCreateTriplet($this->principal, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('address_file'), $this->system);

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('address', $addresses, []);
        $this->mcp->register('cluster', $clusters, []);
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

    /** @return mixed the tool result, with the request scoped to the principal */
    private function asPrincipal(callable $fn)
    {
        $this->mcp->setRequestPrincipal($this->principal);
        try {
            return $fn();
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }
    }

    private static function verbs(array $result): array
    {
        return array_column($result['json']['triplets'] ?? [], 'verb');
    }

    public function testUnscopedStillSeesEveryLink(): void
    {
        $out = $this->callTool('sandra_get_triplets', ['conceptId' => $this->publicAddressId]);

        $this->assertFalse($out['isError']);
        $this->assertContains('memberOf', self::verbs($out));
    }

    public function testLinkIntoAnUnreadableFileIsInvisible(): void
    {
        $out = $this->asPrincipal(fn () => $this->callTool('sandra_get_triplets', ['conceptId' => $this->publicAddressId]));

        $this->assertFalse($out['isError']);
        $verbs = self::verbs($out);
        $this->assertNotContains('memberOf', $verbs, 'the cluster membership must not leak');
        $this->assertContains('hasTag', $verbs, 'a link to bare vocabulary stays visible');
    }

    public function testLinkToAPrivateConceptIsInvisible(): void
    {
        $unscoped = $this->callTool('sandra_get_triplets', ['conceptId' => $this->taggedAddressId]);
        $this->assertContains('hasTag', self::verbs($unscoped));

        $scoped = $this->asPrincipal(fn () => $this->callTool('sandra_get_triplets', ['conceptId' => $this->taggedAddressId]));
        $this->assertNotContains('hasTag', self::verbs($scoped), 'a concept carrying a private file behaves like a private entity');
    }

    public function testCountsAreFilteredToo(): void
    {
        $unscoped = $this->callTool('sandra_get_triplets', ['conceptId' => $this->publicAddressId, 'count_only' => true]);
        $scoped = $this->asPrincipal(fn () => $this->callTool(
            'sandra_get_triplets',
            ['conceptId' => $this->publicAddressId, 'count_only' => true]
        ));

        $this->assertSame(
            $unscoped['json']['counts']['outgoing'] - 1,
            $scoped['json']['counts']['outgoing'],
            'a count that still saw the hidden link would give away what the listing withholds'
        );
    }

    public function testPrivateEntityExposesNothingFromItsOwnSide(): void
    {
        $out = $this->asPrincipal(fn () => $this->callTool('sandra_get_triplets', ['conceptId' => $this->clusterId]));

        $this->assertFalse($out['isError']);
        $this->assertSame([], $out['json']['triplets'], 'every link of a private entity has it as an endpoint');
        $this->assertSame(0, $out['json']['total']);
    }

    public function testTraverseCannotStepIntoAnUnreadableFile(): void
    {
        $args = ['factory' => 'address', 'startId' => $this->publicAddressId, 'verb' => 'memberOf'];

        $unscoped = $this->callTool('sandra_traverse', $args);
        $this->assertSame(1, $unscoped['json']['totalFound']);

        $scoped = $this->asPrincipal(fn () => $this->callTool('sandra_traverse', $args));
        $this->assertFalse($scoped['isError']);
        $this->assertSame(0, $scoped['json']['totalFound']);
    }

    public function testTraverseFromAnUnreadableFactoryStaysSilent(): void
    {
        $out = $this->asPrincipal(fn () => $this->callTool('sandra_traverse', [
            'factory' => 'cluster',
            'startId' => $this->clusterId,
            'verb' => 'memberOf',
            'direction' => 'backward',
        ]));

        $this->assertFalse($out['isError'], 'an error would confirm the entity exists');
        $this->assertSame(0, $out['json']['totalFound']);
    }

    /** @return string[] the `address` ref of every entity a SandraQL query returned */
    private function queryAddresses(string $query): array
    {
        $out = $this->callTool('sandra_ql', ['query' => $query]);
        $this->assertFalse($out['isError'], $out['text']);

        return array_column(array_column($out['json']['entities'] ?? [], 'refs'), 'address');
    }

    public function testBrotherFilterCannotSeeAHiddenLink(): void
    {
        $this->assertSame(['1PWqaoM'], $this->queryAddresses('MATCH address IN address_file WHERE HAS memberOf'));

        $scoped = $this->asPrincipal(fn () => $this->queryAddresses('MATCH address IN address_file WHERE HAS memberOf'));
        $this->assertSame([], $scoped, 'HAS must not confirm a link into an ungranted file');
    }

    public function testNegativeBrotherFilterDoesNotLeakByAbsence(): void
    {
        $this->assertSame(['1N4iYoft'], $this->queryAddresses('MATCH address IN address_file WHERE NOT HAS memberOf'));

        // The point of a view: the member is reported as a non-member, because
        // for this principal the membership link genuinely does not exist. An
        // answer that merely hid the link would exclude it here and give the
        // clustering away.
        $scoped = $this->asPrincipal(fn () => $this->queryAddresses('MATCH address IN address_file WHERE NOT HAS memberOf'));
        sort($scoped);
        $this->assertSame(['1N4iYoft', '1PWqaoM'], $scoped);
    }

    public function testBothToolsAreReachableForPrincipals(): void
    {
        $this->assertContains('sandra_get_triplets', McpServer::ACL_AWARE_TOOLS);
        $this->assertContains('sandra_traverse', McpServer::ACL_AWARE_TOOLS);
    }
}
