<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\Acl\FileManager;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\Mcp\McpServer;

/**
 * Files are declared, never a side effect — and, once filed under the architect
 * file, they stop being enumerable.
 */
class McpFileDeclarationTest extends SandraTestCase
{
    private FileManager $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new FileManager($this->system);
    }

    public function testAnUnknownNameIsNotAFile(): void
    {
        $this->assertFalse($this->files->isDeclared('never_seen_file'));
    }

    public function testDeclaringMakesItOne(): void
    {
        $this->files->create('research_file');
        $this->assertTrue($this->files->isDeclared('research_file'));
    }

    /** Every graph predating the rule keeps working. */
    public function testAFileAlreadyHoldingEntitiesCountsAsDeclared(): void
    {
        (new EntityFactory('note', 'legacy_file', $this->system))->createNew(['body' => 'from before']);

        $this->assertTrue(
            $this->files->isDeclared('legacy_file'),
            'it was evidently intended by whoever filled it'
        );
    }

    public function testArchitectFilingIsAChoice(): void
    {
        $sc = $this->system->systemConcept;
        $cif = (int) $sc->get('contained_in_file');
        $architect = (int) $sc->get(FileManager::ARCHITECT_FILE);

        $hidden = $this->files->create('hidden_file', true);
        $public = $this->files->create('public_file', false);

        $filedUnder = function (int $fileId) use ($cif, $architect): bool {
            $links = DatabaseAdapter::rawGetTriplets($this->system, $fileId, $cif, $architect);
            return $links !== null && $links !== [];
        };

        $this->assertTrue($filedUnder($hidden));
        $this->assertFalse($filedUnder($public), 'a deliberately public boundary stays in the catalogue');
    }

    public function testAReservedNameCannotBecomeAFile(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->files->create('sandra_allow_access');
    }

    // ------------------------------------------------------------- hardening

    public function testHardeningTakesFileNamesOutOfTheCatalogue(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'x']);
        (new EntityFactory('secret', 'secrets_file', $this->system))->createNew(['title' => 'y']);

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $reader = (int) $keys->createNew(['name' => 'reader'])->subjectConcept->idConcept;
        $sc = $this->system->systemConcept;
        DatabaseAdapter::rawCreateTriplet(
            $reader, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('notes_file'), $this->system
        );

        $mcp = new McpServer($this->system);
        $mcp->boot();
        $listed = function () use ($mcp, $reader): array {
            $mcp->setRequestPrincipal($reader);
            try {
                $r = $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                    'params' => ['name' => 'sandra_list_concepts', 'arguments' => ['query' => '%_file%', 'limit' => 200]]]);
            } finally {
                $mcp->setRequestPrincipal(null);
            }
            $json = json_decode($r['result']['content'][0]['text'] ?? '', true);
            return array_column($json['concepts'] ?? [], 'shortname');
        };

        $this->assertContains('secrets_file', $listed(), 'enumerable before hardening');

        $report = $this->files->hardenExistingGraph();
        $this->assertGreaterThan(0, $report['files']);
        $this->assertGreaterThan(0, $report['vocabulary']);

        $after = $listed();
        $this->assertNotContains('secrets_file', $after, 'and gone after');
        $this->assertNotContains('notes_file', $after, 'even the one they can read — the NAME is not the grant');
        $this->assertNotContains('sandra_allow_access', $after, 'system vocabulary too');
    }

    /**
     * The architect file must be readable as what it claims to be: an index.
     * Its cif links carry a `name` ref for that reason — an entity is built
     * FROM its refs, so without one the file would hide names and hand nothing
     * back, a marker one can write but never read.
     */
    public function testTheArchitectFileIsAQueryableIndex(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'x']);
        (new EntityFactory('secret', 'secrets_file', $this->system))->createNew(['title' => 'y']);
        $this->files->hardenExistingGraph();

        $mcp = new McpServer($this->system);
        $mcp->boot();
        $r = $mcp->dispatchMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'sandra_ql',
            'arguments' => ['query' => 'MATCH ' . FileManager::FILE_ISA . ' IN ' . FileManager::ARCHITECT_FILE],
        ]]);
        $json = json_decode($r['result']['content'][0]['text'] ?? '', true);
        $names = array_column(array_column($json['entities'] ?? [], 'refs'), 'name');

        $this->assertContains('notes_file', $names);
        $this->assertContains('secrets_file', $names);
    }

    public function testHardeningLeavesTheArchitectFileBare(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'x']);
        $this->files->hardenExistingGraph();

        $sc = $this->system->systemConcept;
        $architect = (int) $sc->get(FileManager::ARCHITECT_FILE);
        $links = DatabaseAdapter::rawGetTriplets($this->system, $architect, (int) $sc->get('contained_in_file'), $architect);

        $this->assertTrue($links === null || $links === [], 'filing it under itself is a recursion for nothing');
    }

    public function testHardeningIsIdempotent(): void
    {
        (new EntityFactory('note', 'notes_file', $this->system))->createNew(['body' => 'x']);

        $first = $this->files->hardenExistingGraph();
        $second = $this->files->hardenExistingGraph();

        $this->assertSame($first['files'], $second['files'], 'a second pass changes nothing');
        $this->assertTrue($this->files->isDeclared('notes_file'));
    }
}
