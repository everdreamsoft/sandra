<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;

/**
 * Every write tool, under every principal shape.
 *
 * The policy being pinned down:
 *   - a LINK is writable iff every endpoint carrying a contained_in_file sits
 *     in a writable file (create, delete, retarget, storage — all the same);
 *   - an ENTITY is writable iff its own file is;
 *   - the ACL verbs, a link between two bare concepts, and creating a factory
 *     are structural acts and take the write wildcard;
 *   - minting a lone concept only takes some write grant;
 *   - a batch obeys the same rules as the tools it bundles.
 *
 * Theme: annotation on-chain — un agent annote des notes publiques, jamais
 * l'attribution privée.
 */
class McpWriteAclTest extends SandraTestCase
{
    private McpServer $mcp;
    private int $writer;
    private int $admin;
    private int $reader;
    private int $note;
    private int $otherNote;
    private int $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $notes = new EntityFactory('note', 'notes_file', $this->system);
        $this->note = (int) $notes->createNew(['title' => 'public note'])->subjectConcept->idConcept;
        $this->otherNote = (int) $notes->createNew(['title' => 'second note'])->subjectConcept->idConcept;

        $secrets = new EntityFactory('secret', 'secrets_file', $this->system);
        $this->secret = (int) $secrets->createNew(['title' => 'owner attribution'])->subjectConcept->idConcept;

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $this->writer = (int) $keys->createNew(['name' => 'annotating-agent'])->subjectConcept->idConcept;
        $this->admin = (int) $keys->createNew(['name' => 'root-agent'])->subjectConcept->idConcept;
        $this->reader = (int) $keys->createNew(['name' => 'reading-agent'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;

        // The write tools resolve verbs/targets without creating them, so the
        // vocabulary these cases use has to pre-exist. It stays bare — no
        // contained_in_file — which is exactly what the rules below test.
        foreach (['relatesTo', 'owner', 'about', 'hasTag', 'draft', 'unreviewed', 'implies'] as $word) {
            $sc->get($word);
        }

        $grant = function (int $principal, string $verb, string $file) use ($sc): void {
            DatabaseAdapter::rawCreateTriplet($principal, (int) $sc->get($verb), (int) $sc->get($file), $this->system);
        };

        $grant($this->writer, AclResolver::ALLOW_WRITE, 'notes_file');
        $grant($this->writer, AclResolver::ALLOW_ACCESS, 'notes_file');
        $grant($this->admin, AclResolver::ALLOW_WRITE, AclResolver::WILDCARD_FILE);
        $grant($this->admin, AclResolver::ALLOW_ACCESS, AclResolver::WILDCARD_FILE);
        $grant($this->reader, AclResolver::ALLOW_ACCESS, 'notes_file');

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('note', $notes, []);
        $this->mcp->register('secret', $secrets, []);
        $this->mcp->register('api_key', $keys, []);
        $this->mcp->boot();
    }

    /** @return array{isError: bool, text: string, json: mixed} */
    private function call(string $tool, array $arguments, ?int $principal = null): array
    {
        if ($principal !== null) {
            $this->mcp->setRequestPrincipal($principal);
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

    private function assertGranted(array $out, string $why = ''): void
    {
        $this->assertFalse($out['isError'], $why !== '' ? "$why — got: {$out['text']}" : $out['text']);
    }

    private function assertDenied(array $out, string $why): void
    {
        $this->assertTrue($out['isError'], "$why — the call succeeded instead");
    }

    private function concept(string $shortname): int
    {
        return (int) $this->system->systemConcept->get($shortname);
    }

    private function linkId(int $subject, int $verb, int $target): int
    {
        return (int) DatabaseAdapter::rawCreateTriplet($subject, $verb, $target, $this->system);
    }

    // ---------------------------------------------------------- create_triplet

    public function testCreateTripletWithinAWritableFile(): void
    {
        $this->assertGranted($this->call('sandra_create_triplet', [
            'subject' => $this->note, 'verb' => 'relatesTo', 'target' => $this->otherNote,
        ], $this->writer), 'both endpoints in notes_file');
    }

    public function testCreateTripletIntoAnUnwritableFile(): void
    {
        $this->assertDenied($this->call('sandra_create_triplet', [
            'subject' => $this->note, 'verb' => 'owner', 'target' => $this->secret,
        ], $this->writer), 'target sits in secrets_file');
    }

    public function testCreateTripletOutOfAnUnwritableFile(): void
    {
        $this->assertDenied($this->call('sandra_create_triplet', [
            'subject' => $this->secret, 'verb' => 'about', 'target' => $this->note,
        ], $this->writer), 'subject sits in secrets_file');
    }

    public function testTaggingWithBareVocabularyIsAllowed(): void
    {
        $this->assertGranted($this->call('sandra_create_triplet', [
            'subject' => $this->note, 'verb' => 'hasTag', 'target' => 'draft',
        ], $this->writer), 'one writable endpoint, one bare concept');
    }

    public function testLinkingTwoBareConceptsTakesTheWildcard(): void
    {
        $args = ['subject' => 'draft', 'verb' => 'implies', 'target' => 'unreviewed'];

        $this->assertDenied($this->call('sandra_create_triplet', $args, $this->writer), 'shared vocabulary');
        $this->assertGranted($this->call('sandra_create_triplet', $args, $this->admin));
    }

    public function testAclVerbsTakeTheWildcard(): void
    {
        foreach (AclResolver::PROTECTED_VERBS as $verb) {
            $this->assertDenied($this->call('sandra_create_triplet', [
                'subject' => $this->note, 'verb' => $verb, 'target' => 'secrets_file',
            ], $this->writer), "$verb is the ACL itself");
        }

        $this->assertGranted($this->call('sandra_create_triplet', [
            'subject' => $this->note, 'verb' => AclResolver::ALLOW_ACCESS, 'target' => 'secrets_file',
        ], $this->admin));
    }

    public function testSelfPromotionIsRefused(): void
    {
        $this->assertDenied($this->call('sandra_create_triplet', [
            'subject' => $this->writer, 'verb' => AclResolver::ALLOW_ACCESS, 'target' => 'secrets_file',
        ], $this->writer), 'a principal must not grant itself a file');
    }

    // ---------------------------------------------------------- delete_triplet

    public function testDeleteLinkIsGovernedByItsEndpoints(): void
    {
        $hidden = $this->linkId($this->note, $this->concept('owner'), $this->secret);
        $visible = $this->linkId($this->note, $this->concept('relatesTo'), $this->otherNote);

        $this->assertDenied(
            $this->call('sandra_delete_triplet', ['linkId' => $hidden], $this->writer),
            'deleting a link one may not see'
        );
        $this->assertGranted($this->call('sandra_delete_triplet', ['linkId' => $visible], $this->writer));
    }

    // ---------------------------------------------------------- update_triplet

    public function testUpdateLinkStorageIsGovernedByItsEndpoints(): void
    {
        $hidden = $this->linkId($this->note, $this->concept('owner'), $this->secret);
        $visible = $this->linkId($this->note, $this->concept('relatesTo'), $this->otherNote);

        $this->assertDenied(
            $this->call('sandra_update_triplet', ['linkId' => $hidden, 'storage' => 'x'], $this->writer),
            'rewriting the payload of an unwritable link'
        );
        $this->assertGranted($this->call('sandra_update_triplet', ['linkId' => $visible, 'storage' => 'ok'], $this->writer));
    }

    // ----------------------------------------------------------- create_entity

    public function testDenialOnALinkIsIndistinguishableFromAbsence(): void
    {
        $hidden = $this->linkId($this->note, $this->concept('owner'), $this->secret);
        $absent = $hidden + 100000;

        $denied = $this->call('sandra_delete_triplet', ['linkId' => $hidden], $this->writer);
        $missing = $this->call('sandra_delete_triplet', ['linkId' => $absent], $this->writer);

        $this->assertTrue($denied['isError']);
        $this->assertTrue($missing['isError']);
        $this->assertSame(
            str_replace((string) $absent, '<id>', $missing['text']),
            str_replace((string) $hidden, '<id>', $denied['text']),
            'link ids are sequential — a distinguishable denial would enumerate the graph'
        );
    }

    public function testCreateEntityInAWritableFactory(): void
    {
        $this->assertGranted($this->call('sandra_create_entity', [
            'factory' => 'note', 'refs' => ['title' => 'from the agent'],
        ], $this->writer));
    }

    public function testCreateEntityInAnUnwritableFactory(): void
    {
        $this->assertDenied($this->call('sandra_create_entity', [
            'factory' => 'secret', 'refs' => ['title' => 'nope'],
        ], $this->writer), 'secrets_file carries no write grant');
    }

    public function testCreateEntityCannotSmuggleInANewFactory(): void
    {
        $args = ['factory' => 'invented', 'refs' => ['title' => 'x']];

        $this->assertDenied($this->call('sandra_create_entity', $args, $this->writer), 'unknown factory = new ACL unit');
        $this->assertGranted($this->call('sandra_create_entity', $args, $this->admin));
    }

    // ----------------------------------------------------------- update_entity

    public function testUpdateEntityIsGovernedByItsOwnFile(): void
    {
        $this->assertGranted($this->call('sandra_update_entity', [
            'factory' => 'note', 'id' => $this->note, 'refs' => ['title' => 'edited'],
        ], $this->writer));

        $this->assertDenied($this->call('sandra_update_entity', [
            'factory' => 'secret', 'id' => $this->secret, 'refs' => ['title' => 'edited'],
        ], $this->writer), 'the entity lives in secrets_file');
    }

    // ----------------------------------------------------------- link_entities

    public function testLinkEntitiesFollowsTheEndpointRule(): void
    {
        $this->assertGranted($this->call('sandra_link_entities', [
            'factory' => 'note', 'sourceId' => $this->note, 'verb' => 'relatesTo', 'target' => $this->otherNote,
        ], $this->writer));

        $this->assertDenied($this->call('sandra_link_entities', [
            'factory' => 'note', 'sourceId' => $this->note, 'verb' => 'owner', 'target' => $this->secret,
        ], $this->writer), 'a brother link is still a link');
    }

    // ---------------------------------------------------------- create_concept

    public function testLinkEntitiesAcceptsABareConceptTarget(): void
    {
        $this->assertGranted($this->call('sandra_link_entities', [
            'factory' => 'note', 'sourceId' => $this->note, 'verb' => 'hasTag', 'target' => 'draft',
        ], $this->writer), 'tagging is an ordinary write on the note');
    }

    public function testMintingAConceptTakesSomeWriteGrant(): void
    {
        $this->assertGranted($this->call('sandra_create_concept', ['name' => 'wash_trade'], $this->writer));
        $this->assertDenied(
            $this->call('sandra_create_concept', ['name' => 'from_reader'], $this->reader),
            'a read-only principal must not grow the dictionary'
        );
    }

    // ---------------------------------------------------------- create_factory

    public function testCreatingAFactoryTakesTheWildcard(): void
    {
        $this->assertDenied($this->call('sandra_create_factory', ['name' => 'cluster'], $this->writer), 'new ACL unit');
        $this->assertGranted($this->call('sandra_create_factory', ['name' => 'cluster'], $this->admin));
    }

    // -------------------------------------------------------------------- batch

    public function testBatchObeysTheSameRules(): void
    {
        $this->assertGranted($this->call('sandra_batch', [
            'concepts' => ['seen_by_agent'],
            'entities' => [['factory' => 'note', 'refs' => ['title' => 'batched']]],
            'triplets' => [['subject' => $this->note, 'verb' => 'relatesTo', 'target' => $this->otherNote]],
        ], $this->writer), 'everything inside notes_file');
    }

    public function testBatchCannotSmuggleALinkIntoAnUnwritableFile(): void
    {
        $this->assertDenied($this->call('sandra_batch', [
            'triplets' => [['subject' => $this->note, 'verb' => 'owner', 'target' => $this->secret]],
        ], $this->writer), 'a batch is not a way around the endpoint rule');
    }

    public function testBatchCannotSmuggleAnEntityIntoAnUnwritableFile(): void
    {
        $this->assertDenied($this->call('sandra_batch', [
            'entities' => [['factory' => 'secret', 'refs' => ['title' => 'nope']]],
        ], $this->writer), 'entity phase obeys create_entity');
    }

    public function testBatchRefusesBeforeWritingAnything(): void
    {
        $out = $this->call('sandra_batch', [
            'concepts' => ['never_minted'],
            'triplets' => [['subject' => $this->note, 'verb' => 'owner', 'target' => $this->secret]],
        ], $this->writer);

        $this->assertTrue($out['isError']);
        $this->assertNull(
            $this->system->systemConcept->get('never_minted', null, false),
            'the batch has no transaction, so the refusal has to land before phase 1'
        );
    }

    public function testBatchConceptPhaseRefusesAReadOnlyPrincipal(): void
    {
        $this->assertDenied($this->call('sandra_batch', [
            'concepts' => ['minted_by_reader'],
        ], $this->reader), 'concept phase obeys create_concept');
    }

    // ------------------------------------------------------------- write ⊅ read

    public function testAWriteOnlyPrincipalCanWriteAndStillNotRead(): void
    {
        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $blind = (int) $keys->createNew(['name' => 'drop-box'])->subjectConcept->idConcept;
        DatabaseAdapter::rawCreateTriplet(
            $blind,
            (int) $this->system->systemConcept->get(AclResolver::ALLOW_WRITE),
            (int) $this->system->systemConcept->get('notes_file'),
            $this->system
        );

        $this->assertGranted(
            $this->call('sandra_create_entity', ['factory' => 'note', 'refs' => ['title' => 'dropped']], $blind),
            'write grant alone is enough to write'
        );

        $read = $this->call('sandra_ql', ['query' => 'MATCH note IN notes_file'], $blind);
        $this->assertFalse($read['isError']);
        $this->assertSame(0, $read['json']['count'], 'and it still reads nothing — the grants are independent');
    }

    // --------------------------------------------------------------- unscoped

    public function testUnscopedWritesAreUntouched(): void
    {
        $this->assertGranted($this->call('sandra_create_triplet', [
            'subject' => $this->note, 'verb' => 'owner', 'target' => $this->secret,
        ]), 'no principal, no guard');

        $this->assertGranted($this->call('sandra_create_factory', ['name' => 'anything']));
        $this->assertGranted($this->call('sandra_create_concept', ['name' => 'anything_at_all']));
    }
}
