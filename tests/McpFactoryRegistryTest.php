<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\FactoryDiscovery;
use SandraCore\Mcp\McpServer;

/**
 * The factory registry, keyed by the (is_a, contained_in_file) PAIR.
 *
 * Facets make several files per is_a the norm. Keying by is_a alone meant the
 * first pair the optimiser happened to return took the bare name — with no
 * ORDER BY, so it flipped between boots — and the write tools silently
 * discarded an explicit file, which is a write into someone else's facet.
 */
class McpFactoryRegistryTest extends SandraTestCase
{
    private function boot(): McpServer
    {
        $mcp = new McpServer($this->system);
        $mcp->discover();
        $mcp->boot();
        // discovery is lazy: one dispatch forces it
        $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        return $mcp;
    }

    private function names(): array
    {
        return array_keys((new FactoryDiscovery($this->system))->discover());
    }

    public function testTheOldestPairKeepsTheBareName(): void
    {
        $notes = new EntityFactory('note', 'notes_file', $this->system);
        $notes->createNew(['body' => 'first']);

        $this->assertContains('note', $this->names(), 'a single file keeps the bare is_a');

        // A second file for the same is_a — a facet, or another user's notes.
        $second = new EntityFactory('note', 'alice_file', $this->system);
        $second->createNew(['body' => 'alice']);

        $names = $this->names();
        $this->assertContains('note', $names, 'the oldest pair must not lose its name');
        $this->assertContains('note@alice_file', $names);
    }

    public function testNamingIsStableAcrossBoots(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'a']);
        (new EntityFactory('note', 'alice_file', $this->system))->createNew(['body' => 'b']);
        (new EntityFactory('note', 'bob_file', $this->system))->createNew(['body' => 'c']);

        $first = $this->names();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($first, $this->names(), 'discovery must not depend on row order');
        }
        $this->assertSame(['note', 'note@alice_file', 'note@bob_file'], array_values(array_filter(
            $first,
            static fn (string $n): bool => str_starts_with($n, 'note')
        )));
    }

    public function testAnExplicitFileIsNoLongerDiscarded(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'canonical']);
        $mcp = $this->boot();

        $mcp->getToolRegistry()->call('sandra_create_entity', [
            'factory' => 'note',
            'contained_in_file' => 'alice_file',
            'refs' => ['body' => 'in alice file'],
        ]);

        // Check where it actually landed, not what the response echoed back.
        $bodies = static fn (array $result): array => array_column(
            array_column($result['entities'] ?? [], 'refs'),
            'body'
        );

        $inAlice = $bodies($mcp->getToolRegistry()->call('sandra_ql', ['query' => 'MATCH note IN alice_file']));
        $inCanonical = $bodies($mcp->getToolRegistry()->call('sandra_ql', ['query' => 'MATCH note IN notes_file']));

        $this->assertContains('in alice file', $inAlice, 'the named file must win over the incumbent');
        $this->assertNotContains('in alice file', $inCanonical, 'and it must NOT land in the registered one');
    }

    public function testCreateFactoryWithAnotherFileMakesASecondFacet(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'canonical']);
        $mcp = $this->boot();

        $out = $mcp->getToolRegistry()->call('sandra_create_factory', [
            'name' => 'note',
            'contained_in_file' => 'alice_file',
        ]);

        $this->assertTrue($out['created'], 'previously answered "already exists" and dropped the file');
        $this->assertSame('alice_file', $out['entityContainedIn']);
    }

    public function testTraversalMaterialisesTheFacetTheCallerMayRead(): void
    {
        // One concept, two facets: a public note and Alice's private one.
        $public = new EntityFactory('note', 'notes_file', $this->system);
        $entity = $public->createNew(['body' => 'public body']);
        $concept = (int) $entity->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $link = (int) DatabaseAdapter::rawCreateTriplet(
            $concept, (int) $sc->get('contained_in_file'), (int) $sc->get('alice_file'), $this->system
        );
        DatabaseAdapter::rawCreateReference($link, (int) $sc->get('body'), 'alice private body', $this->system);

        $anchors = new EntityFactory('anchor', 'anchors_file', $this->system);
        $anchor = (int) $anchors->createNew(['name' => 'start'])->subjectConcept->idConcept;
        DatabaseAdapter::rawCreateTriplet($anchor, (int) $sc->get('mentions'), $concept, $this->system);

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $alice = (int) $keys->createNew(['name' => 'alice'])->subjectConcept->idConcept;
        foreach (['alice_file', 'anchors_file'] as $file) {
            DatabaseAdapter::rawCreateTriplet(
                $alice, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get($file), $this->system
            );
        }

        $mcp = $this->boot();
        $mcp->setRequestPrincipal($alice);
        try {
            $response = $mcp->dispatchMessage([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'sandra_traverse', 'arguments' => [
                    'factory' => 'anchor', 'startId' => $anchor, 'verb' => 'mentions',
                ]],
            ]);
        } finally {
            $mcp->setRequestPrincipal(null);
        }

        $json = json_decode($response['result']['content'][0]['text'] ?? '', true);
        $this->assertSame(1, $json['totalFound'] ?? 0);
        $this->assertSame(
            'alice private body',
            $json['entities'][0]['refs']['body'] ?? null,
            'she reads alice_file and not notes_file, so the walk must load HER facet'
        );
    }
}
