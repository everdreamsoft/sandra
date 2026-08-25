<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\Acl\TripletVisibility;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;

/**
 * The read tools that were previously refused wholesale, now filtered.
 *
 * Same rule throughout: entities follow their file, links follow their
 * endpoints, and a concept follows its own contained_in_file when it has one —
 * which is what makes a private tag private.
 *
 * Theme: annotation on-chain — les notes publiques sont lisibles, l'attribution
 * ne l'est pas, et rien ne doit trahir son existence.
 */
class McpReadAclTest extends SandraTestCase
{
    private McpServer $mcp;
    private int $principal;
    private int $note;
    private int $secret;
    private int $privateTag;

    protected function setUp(): void
    {
        parent::setUp();

        $notes = new EntityFactory('note', 'notes_file', $this->system);
        $this->note = (int) $notes->createNew(['title' => 'wallet 1PWqaoM'])->subjectConcept->idConcept;

        $secrets = new EntityFactory('secret', 'secrets_file', $this->system);
        $this->secret = (int) $secrets->createNew(['title' => 'wallet 1PWqaoM belongs to EDS'])->subjectConcept->idConcept;

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $this->principal = (int) $keys->createNew(['name' => 'reader'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        $cif = (int) $sc->get('contained_in_file');

        // A link from a readable note into the unreadable file, carrying a ref.
        $hidden = (int) DatabaseAdapter::rawCreateTriplet($this->note, (int) $sc->get('owner'), $this->secret, $this->system);
        DatabaseAdapter::rawCreateReference($hidden, (int) $sc->get('confidence'), '0.9', $this->system);

        // A visible link, also with a ref.
        $shown = (int) DatabaseAdapter::rawCreateTriplet($this->note, (int) $sc->get('hasTag'), (int) $sc->get('dispenser'), $this->system);
        DatabaseAdapter::rawCreateReference($shown, (int) $sc->get('confidence'), '0.4', $this->system);

        // A bare concept made private by giving it a file of its own.
        $this->privateTag = (int) $sc->get('owner_eds');
        DatabaseAdapter::rawCreateTriplet($this->privateTag, $cif, (int) $sc->get('secrets_file'), $this->system);

        DatabaseAdapter::rawCreateTriplet($this->principal, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('notes_file'), $this->system);

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('note', $notes, []);
        $this->mcp->register('secret', $secrets, []);
        $this->mcp->register('api_key', $keys, []);
        $this->mcp->boot();
    }

    private function call(string $tool, array $arguments, bool $scoped = true): array
    {
        if ($scoped) {
            $this->mcp->setRequestPrincipal($this->principal);
        }

        try {
            $response = $this->mcp->dispatchMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
        } finally {
            $this->mcp->setRequestPrincipal(null);
        }

        $content = $response['result']['content'][0]['text'] ?? '';

        return [
            'isError' => (bool) ($response['result']['isError'] ?? false),
            'text' => $content,
            'json' => json_decode($content, true),
        ];
    }

    // ------------------------------------------------------------ list_entities

    public function testListEntitiesIsSilentOnAnUnreadableFactory(): void
    {
        $granted = $this->call('sandra_list_entities', ['factory' => 'note']);
        $this->assertFalse($granted['isError']);
        $this->assertSame(1, $granted['json']['count']);

        $denied = $this->call('sandra_list_entities', ['factory' => 'secret']);
        $this->assertFalse($denied['isError'], 'an error would confirm the factory holds something');
        $this->assertSame(0, $denied['json']['count']);
        $this->assertSame(0, $denied['json']['total']);
    }

    public function testListEntitiesBrotherFiltersDoNotLeak(): void
    {
        $args = [
            'factory' => 'note',
            'brother_filters' => [['verb' => 'owner', 'target' => $this->secret]],
        ];

        $unscoped = $this->call('sandra_list_entities', $args, false);
        $this->assertSame(1, $unscoped['json']['count'], 'the note is linked to the secret');

        $scoped = $this->call('sandra_list_entities', $args);
        $this->assertSame(0, $scoped['json']['count'], 'brother_filters join across files just like HAS');
    }

    // -------------------------------------------------------------- get_entity

    public function testGetEntityDenialLooksExactlyLikeAbsence(): void
    {
        $this->assertFalse($this->call('sandra_get_entity', ['factory' => 'note', 'id' => $this->note])['isError']);

        $denied = $this->call('sandra_get_entity', ['factory' => 'secret', 'id' => $this->secret]);
        $absent = $this->call('sandra_get_entity', ['factory' => 'note', 'id' => 999999]);

        $this->assertTrue($denied['isError']);
        $this->assertTrue($absent['isError']);
        $this->assertSame(
            str_replace(['999999', 'note'], ['<id>', '<factory>'], $absent['text']),
            str_replace([(string) $this->secret, 'secret'], ['<id>', '<factory>'], $denied['text'])
        );
    }

    // ------------------------------------------------------------------ search

    /** Cross-factory search answers under `results`; a single factory under `items`. */
    private static function titles(array $result, string $key): array
    {
        return array_column(array_column($result['json'][$key] ?? [], 'refs'), 'title');
    }

    public function testSearchSkipsUnreadableFactories(): void
    {
        $unscoped = self::titles($this->call('sandra_search', ['query' => '%1PWqaoM%'], false), 'results');
        $this->assertContains('wallet 1PWqaoM', $unscoped);
        $this->assertContains('wallet 1PWqaoM belongs to EDS', $unscoped, 'both match without a principal');

        $scoped = self::titles($this->call('sandra_search', ['query' => '%1PWqaoM%']), 'results');
        $this->assertContains('wallet 1PWqaoM', $scoped);
        $this->assertNotContains('wallet 1PWqaoM belongs to EDS', $scoped);
    }

    public function testSearchDoesNotSurfaceAPrivateConcept(): void
    {
        $names = static fn (array $r): array => array_column(
            array_filter($r['json']['results'] ?? [], static fn (array $i): bool => ($i['type'] ?? '') === 'system_concept'),
            'shortname'
        );

        $this->assertContains('owner_eds', $names($this->call('sandra_search', ['query' => 'owner_eds'], false)));
        $this->assertNotContains('owner_eds', $names($this->call('sandra_search', ['query' => 'owner_eds'])));
    }

    public function testSearchInAnUnreadableFactoryIsSilent(): void
    {
        $out = $this->call('sandra_search', ['factory' => 'secret', 'query' => 'EDS']);
        $this->assertFalse($out['isError']);
        $this->assertSame(0, $out['json']['count']);
    }

    // ---------------------------------------------------------- get_references

    public function testReferencesFollowTheirLink(): void
    {
        $unscoped = $this->call('sandra_get_references', ['conceptId' => $this->note], false);
        $this->assertStringContainsString('0.9', $unscoped['text'], 'the hidden link carries confidence 0.9');

        $scoped = $this->call('sandra_get_references', ['conceptId' => $this->note]);
        $this->assertFalse($scoped['isError']);
        $this->assertStringNotContainsString('0.9', $scoped['text'], 'a ref is as visible as the link it hangs on');
        $this->assertStringContainsString('0.4', $scoped['text'], 'the visible link keeps its ref');
    }

    // ----------------------------------------------------------- list_concepts

    public function testPrivateConceptsAreAbsentFromTheListingAndItsTotal(): void
    {
        $unscoped = $this->call('sandra_list_concepts', ['query' => '%owner%'], false);
        $this->assertContains('owner_eds', array_column($unscoped['json']['concepts'], 'shortname'));

        $scoped = $this->call('sandra_list_concepts', ['query' => '%owner%']);
        $this->assertNotContains('owner_eds', array_column($scoped['json']['concepts'], 'shortname'));
        $this->assertSame(
            $unscoped['json']['total'] - 1,
            $scoped['json']['total'],
            'the count must not give away what the page withholds'
        );
    }

    // ------------------------------------------------------------ find_concept

    public function testResolvingAPrivateConceptByNameFails(): void
    {
        $this->assertTrue($this->call('sandra_find_concept', ['name' => 'owner_eds'], false)['json']['found']);

        $scoped = $this->call('sandra_find_concept', ['name' => 'owner_eds']);
        $this->assertFalse($scoped['json']['found'], 'naming it must not confirm it');
        $this->assertNull($scoped['json']['id']);

        $this->assertTrue($this->call('sandra_find_concept', ['name' => 'dispenser'])['json']['found'], 'ordinary vocabulary still resolves');
    }

    // --------------------------------- the filter behind sandra_semantic_search

    public function testVisibleConceptsIsTheFilterSemanticSearchApplies(): void
    {
        $visibility = TripletVisibility::forAccess(
            $this->system,
            AclResolver::resolve($this->system, $this->principal)
        );

        $this->assertNotNull($visibility);
        $this->assertSame(
            [$this->note, (int) $this->system->systemConcept->get('dispenser')],
            $visibility->visibleConcepts([
                $this->note,
                $this->secret,
                $this->privateTag,
                (int) $this->system->systemConcept->get('dispenser'),
            ]),
            'entities follow their file, bare vocabulary survives, a filed concept does not'
        );
    }
}
