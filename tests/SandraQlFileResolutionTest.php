<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\EntityFactory;
use SandraCore\Mcp\Tools\SandraQlTool;
use SandraCore\Setup;
use SandraCore\System;

/**
 * sandra_ql without an `IN file` clause must resolve the container file from
 * the discovered factory registry (like every other MCP tool), not guess
 * `{isa}_file`. Factories whose container does not follow that convention
 * (CsCannon-style `blockchainEvent` → `blockchainEventFile`) were invisible
 * to SandraQL otherwise.
 * Theme: phares (nom, hauteur), rangés dans `littoralFile`.
 */
class SandraQlFileResolutionTest extends TestCase
{
    private System $system;
    /** @var array<string, array{factory: EntityFactory}> */
    private array $factories = [];

    protected function setUp(): void
    {
        $flusher = new System('phpUnitQlFile', true);
        Setup::flushDatagraph($flusher);
        $this->system = new System('phpUnitQlFile', true);

        $phares = new EntityFactory('phare', 'littoralFile', $this->system);
        $phares->createNew(['nom' => 'Cordouan', 'hauteur' => '68']);
        $phares->createNew(['nom' => 'Eckmuhl', 'hauteur' => '65']);
        $allume = $phares->createNew(['nom' => 'Ar-Men', 'hauteur' => '37']);
        $allume->setBrotherEntity('etat', 'allume', []);

        $this->factories = ['phare' => ['factory' => $phares]];
    }

    public function testFileIsResolvedFromRegistryWhenOmitted(): void
    {
        $tool = new SandraQlTool($this->system, $this->factories);
        $result = $tool->execute(['query' => 'MATCH phare LIMIT 10']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('littoralFile', $result['ast']['match']['file']);
        $this->assertCount(3, $result['entities']);
    }

    public function testHasFilterWorksWithResolvedFile(): void
    {
        $tool = new SandraQlTool($this->system, $this->factories);
        $result = $tool->execute(['query' => 'MATCH phare WHERE HAS etat -> allume LIMIT 10']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(1, $result['entities']);
        $this->assertSame('Ar-Men', $result['entities'][0]['refs']['nom']);
    }

    public function testExplicitFileIsNotOverridden(): void
    {
        $tool = new SandraQlTool($this->system, $this->factories);
        $result = $tool->execute(['query' => 'MATCH phare IN autreFile LIMIT 10']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('autreFile', $result['ast']['match']['file']);
        $this->assertCount(0, $result['entities']);
    }

    public function testUnknownIsaFallsBackToConvention(): void
    {
        $tool = new SandraQlTool($this->system, $this->factories);
        $result = $tool->execute(['query' => 'MATCH inconnu LIMIT 10']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayNotHasKey('file', $result['ast']['match']);
        $this->assertCount(0, $result['entities']);
    }
}
