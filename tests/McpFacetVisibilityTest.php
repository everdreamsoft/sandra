<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\Acl\TripletVisibility;
use SandraCore\Acl\WriteGuard;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\Mcp\McpServer;

/**
 * Facets: one concept, several `contained_in_file`, one disjoint ref set each.
 *
 * The case that drives it: a Bitcoin address already exists in the public graph
 * and nobody creates it. Alice "tracks" it by attaching HER facet — her label,
 * in her file. Bob attaches his. Neither sees the other's, and — the part the
 * old conservative rule got wrong — the address stays public for everyone.
 */
class McpFacetVisibilityTest extends SandraTestCase
{
    private McpServer $mcp;
    private int $wallet;
    private int $alice;
    private int $bob;
    private int $carol;
    private int $secret;

    protected function setUp(): void
    {
        parent::setUp();

        // The public graph: the address is already there.
        $addresses = new EntityFactory('btcAddress', 'blockchainAddressFile', $this->system);
        $this->wallet = (int) $addresses->createNew(['address' => '1PWqaoM'])->subjectConcept->idConcept;

        // Something private that is NOT a facet — its only file is unreadable.
        $secrets = new EntityFactory('secret', 'secrets_file', $this->system);
        $this->secret = (int) $secrets->createNew(['title' => 'owner attribution'])->subjectConcept->idConcept;

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $this->alice = (int) $keys->createNew(['name' => 'alice'])->subjectConcept->idConcept;
        $this->bob = (int) $keys->createNew(['name' => 'bob'])->subjectConcept->idConcept;
        $this->carol = (int) $keys->createNew(['name' => 'carol'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $cif = (int) $sc->get('contained_in_file');

        // Alice's facet on the public address, and Bob's.
        $this->facet($this->wallet, 'alice_file', ['label' => 'cold wallet EDS']);
        $this->facet($this->wallet, 'bob_file', ['label' => 'whale to watch']);

        $grant = function (int $principal, string $file) use ($sc): void {
            DatabaseAdapter::rawCreateTriplet(
                $principal, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get($file), $this->system
            );
        };
        $write = function (int $principal, string $file) use ($sc): void {
            DatabaseAdapter::rawCreateTriplet(
                $principal, (int) $sc->get(AclResolver::ALLOW_WRITE), (int) $sc->get($file), $this->system
            );
        };

        foreach ([$this->alice, $this->bob, $this->carol] as $p) {
            $grant($p, 'blockchainAddressFile');
        }
        $grant($this->alice, 'alice_file');
        $write($this->alice, 'alice_file');
        $grant($this->bob, 'bob_file');

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('btcAddress', $addresses, []);
        $this->mcp->register('secret', $secrets, []);
        $this->mcp->register('api_key', $keys, []);
        $this->mcp->boot();
    }

    /** File a concept under another file, with its own refs. That is a facet. */
    private function facet(int $concept, string $file, array $refs): int
    {
        $sc = $this->system->systemConcept;
        $link = (int) DatabaseAdapter::rawCreateTriplet(
            $concept, (int) $sc->get('contained_in_file'), (int) $sc->get($file), $this->system
        );
        foreach ($refs as $key => $value) {
            DatabaseAdapter::rawCreateReference($link, (int) $sc->get((string) $key), (string) $value, $this->system);
        }

        return $link;
    }

    private function call(string $tool, array $arguments, ?int $principal): array
    {
        if ($principal !== null) {
            $this->mcp->setRequestPrincipal($principal);
        }

        try {
            $response = $this->mcp->dispatchMessage([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }

        $text = $response['result']['content'][0]['text'] ?? '';

        return ['isError' => (bool) ($response['result']['isError'] ?? false), 'text' => $text, 'json' => json_decode($text, true)];
    }

    private function guard(int $principal): WriteGuard
    {
        return WriteGuard::forAccess($this->system, AclResolver::resolve($this->system, $principal));
    }

    // ------------------------------------------------- the regression that drove this

    public function testAPrivateFacetDoesNotUnpublishThePublicConcept(): void
    {
        foreach (['alice' => $this->alice, 'bob' => $this->bob, 'carol' => $this->carol] as $who => $p) {
            $out = $this->call('sandra_ql', ['query' => 'MATCH btcAddress IN blockchainAddressFile'], $p);
            $this->assertSame(1, $out['json']['count'], "$who must still see the public address");
            $this->assertSame('1PWqaoM', $out['json']['entities'][0]['refs']['address']);
        }
    }

    // ------------------------------------------------------------- containment rule

    public function testAFacetLinkIsVisibleOnlyToItsFileReaders(): void
    {
        $files = function (int $p): array {
            $out = $this->call('sandra_get_triplets', ['conceptId' => $this->wallet], $p);
            return array_column(
                array_filter($out['json']['triplets'] ?? [], static fn (array $t): bool => $t['verb'] === 'contained_in_file'),
                'target'
            );
        };

        $this->assertSame(['blockchainAddressFile'], $files($this->carol), 'carol must not learn the facets exist');
        $aliceFiles = $files($this->alice);
        sort($aliceFiles);
        $this->assertSame(['alice_file', 'blockchainAddressFile'], $aliceFiles, 'her own facet plus the public one');
        $this->assertNotContains('bob_file', $aliceFiles);
    }

    // ------------------------------------------------------------- per-facet refs

    public function testRefsFollowTheFacetTheyHangOn(): void
    {
        $refs = fn (int $p): string => $this->call('sandra_get_references', ['conceptId' => $this->wallet], $p)['text'];

        $carol = $refs($this->carol);
        $this->assertStringContainsString('1PWqaoM', $carol, 'the public ref stays public');
        $this->assertStringNotContainsString('cold wallet EDS', $carol);
        $this->assertStringNotContainsString('whale to watch', $carol);

        $alice = $refs($this->alice);
        $this->assertStringContainsString('1PWqaoM', $alice);
        $this->assertStringContainsString('cold wallet EDS', $alice, 'her own facet');
        $this->assertStringNotContainsString('whale to watch', $alice, "not Bob's");
    }

    // ----------------------------------------------------------------- the pins

    public function testAnEntityWhoseOnlyFileIsUnreadableStaysInvisible(): void
    {
        $out = $this->call('sandra_ql', ['query' => 'MATCH secret IN secrets_file'], $this->alice);
        $this->assertSame(0, $out['json']['count'], 'the naive-OR trap: no readable file means hidden');

        $triplets = $this->call('sandra_get_triplets', ['conceptId' => $this->secret], $this->alice);
        $this->assertSame([], $triplets['json']['triplets']);
    }

    public function testBareVocabularyIsStillVisible(): void
    {
        $out = $this->call('sandra_find_concept', ['name' => 'contained_in_file'], $this->alice);
        $this->assertTrue($out['json']['found']);
    }

    public function testStrictAndPermissiveRulesDisagreeOnlyOnFacets(): void
    {
        $visibility = TripletVisibility::forAccess($this->system, AclResolver::resolve($this->system, $this->alice));

        $this->assertSame([$this->wallet], $visibility->visibleConcepts([$this->wallet, $this->secret]));
        $this->assertSame([], $visibility->fullyVisibleConcepts([$this->wallet, $this->secret]),
            'the wallet has a facet alice cannot read, so the strict rule excludes it too');
    }

    /**
     * File the FILE CONCEPTS themselves under an architect file, so they stop
     * being enumerable. The endpoint rule would then hide every cif link — and
     * with it the refs hanging on it, from their own owner. The exemption on a
     * cif link's target is what keeps that from happening; the containment
     * clause remains the rule about which facet is readable.
     */
    public function testFilingTheFileConceptsThemselvesDoesNotBlindTheirOwners(): void
    {
        $sc = $this->system->systemConcept;
        $cif = (int) $sc->get('contained_in_file');
        foreach (['alice_file', 'bob_file', 'blockchainAddressFile', 'secrets_file'] as $file) {
            DatabaseAdapter::rawCreateTriplet(
                (int) $sc->get($file), $cif, (int) $sc->get('sandra_architect_file'), $this->system
            );
        }

        $refs = $this->call('sandra_get_references', ['conceptId' => $this->wallet], $this->alice)['text'];
        $this->assertStringContainsString('cold wallet EDS', $refs, 'she must still read her own facet');
        $this->assertStringContainsString('1PWqaoM', $refs, 'and the public one she is granted');
        $this->assertStringNotContainsString('whale to watch', $refs, "still not Bob's");

        $carol = $this->call('sandra_get_references', ['conceptId' => $this->wallet], $this->carol)['text'];
        $this->assertStringNotContainsString('cold wallet EDS', $carol);

        // And the point of the exercise: the file names stop being enumerable.
        $listed = $this->call('sandra_list_concepts', ['query' => '%_file%'], $this->carol);
        $this->assertNotContains('alice_file', array_column($listed['json']['concepts'] ?? [], 'shortname'));
    }

    /**
     * A facet with no caller-supplied ref: "I follow this address", the presence
     * being the whole content. It must still be a real, visible record —
     * populateLocal builds entities from the REFERENCES query, so the
     * creationTimestamp createNew has always written is what keeps such a facet
     * from being invisible to its own owner.
     */
    public function testAFacetWithNoCallerRefIsStillARecord(): void
    {
        $second = (int) (new EntityFactory('btcAddress', 'blockchainAddressFile', $this->system))
            ->createNew(['address' => '1N4iYoft'])->subjectConcept->idConcept;

        (new EntityFactory('btcAddress', 'alice_file', $this->system))->attachFacet($second, []);

        // NB: SandraQL answers with `id` = the cif LINK id and `conceptId`
        // separately, unlike sandra_create_entity where `id` IS the concept.
        $out = $this->call('sandra_ql', ['query' => 'MATCH btcAddress IN alice_file'], $this->alice);
        $this->assertContains(
            $second,
            array_column($out['json']['entities'] ?? [], 'conceptId'),
            'presence is the information'
        );

        $denied = $this->call('sandra_ql', ['query' => 'MATCH btcAddress IN alice_file'], $this->carol);
        $this->assertSame(0, $denied['json']['count'], 'and it stays private all the same');
    }

    // ------------------------------------------------------------------- writes

    public function testAttachingAFacetToAReadablePublicConceptIsAllowed(): void
    {
        $this->guard($this->alice)->assertCanAttachFacet(
            $this->wallet,
            (int) $this->system->systemConcept->get('alice_file')
        );
        $this->assertTrue(true, 'she writes her file and can read the address — the whole point');
    }

    public function testAttachingAFacetToAnUnreadableConceptIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->guard($this->alice)->assertCanAttachFacet(
            $this->secret,
            (int) $this->system->systemConcept->get('alice_file')
        );
    }

    public function testFilingIntoAFileOneCannotWriteIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->guard($this->alice)->assertCanAttachFacet(
            $this->wallet,
            (int) $this->system->systemConcept->get('blockchainAddressFile')
        );
    }

    public function testAFacetDoesNotFreezeTheOtherFacetsOwner(): void
    {
        // Alice writes alice_file and nothing else; the address lives in a file
        // she cannot write. Under the old whole-entity rule this was refused.
        $this->guard($this->alice)->assertCanWriteFacet($this->wallet, 'alice_file');
        $this->assertTrue(true);

        $this->expectException(AccessDeniedException::class);
        $this->guard($this->alice)->assertCanWriteFacet($this->wallet, 'blockchainAddressFile');
    }
}
