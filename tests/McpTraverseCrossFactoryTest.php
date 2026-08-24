<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\EntityFactory;
use SandraCore\Mcp\McpServer;
use SandraCore\Setup;
use SandraCore\System;

/**
 * sandra_traverse must follow a verb wherever it lands: into another factory,
 * onto a bare system concept, and backwards from the target — without loading
 * whole factories. The old implementation only saw neighbours already loaded
 * in the start factory, so every cross-factory link returned nothing.
 * Theme: expeditions polaires — une expedition part d'un port et atteint une base.
 */
class McpTraverseCrossFactoryTest extends TestCase
{
    private System $system;
    private McpServer $mcp;
    private int $expeditionId;
    private int $portId;
    private int $baseId;

    protected function setUp(): void
    {
        $flusher = new System('phpUnitTraverseX', true);
        Setup::flushDatagraph($flusher);
        $this->system = new System('phpUnitTraverseX', true);

        $ports = new EntityFactory('port', 'portsFile', $this->system);
        $port = $ports->createNew(['nom' => 'Ushuaia']);

        $bases = new EntityFactory('base', 'basesFile', $this->system);
        $base = $bases->createNew(['nom' => 'Dumont-d-Urville']);
        $relais = $bases->createNew(['nom' => 'Cap Prudhomme']);
        $base->setBrotherEntity('ravitaillePar', $relais, []);

        $expeditions = new EntityFactory('expedition', 'expeditionsFile', $this->system);
        $exp = $expeditions->createNew(['nom' => 'Polaris-1']);
        $exp->setBrotherEntity('partDe', $port, []);
        $exp->setBrotherEntity('atteint', $base, []);
        $exp->setBrotherEntity('saison', 'ete_austral', []);

        $this->expeditionId = (int)$exp->subjectConcept->idConcept;
        $this->portId = (int)$port->subjectConcept->idConcept;
        $this->baseId = (int)$base->subjectConcept->idConcept;

        $this->mcp = new McpServer($this->system);
        $this->mcp->register('expedition', new EntityFactory('expedition', 'expeditionsFile', $this->system));
        $this->mcp->register('port', new EntityFactory('port', 'portsFile', $this->system));
        $this->mcp->register('base', new EntityFactory('base', 'basesFile', $this->system));
        $this->mcp->boot();
    }

    private function traverse(array $args): array
    {
        return $this->mcp->getToolRegistry()->call('sandra_traverse', $args);
    }

    public function testForwardIntoAnotherFactory(): void
    {
        $r = $this->traverse(['factory' => 'expedition', 'startId' => $this->expeditionId, 'verb' => 'partDe']);

        $this->assertSame(1, $r['totalFound']);
        $this->assertSame($this->portId, $r['entities'][0]['id']);
        $this->assertSame('port', $r['entities'][0]['factory']);
        $this->assertSame('Ushuaia', $r['entities'][0]['refs']['nom']);
        $this->assertSame(1, $r['entities'][0]['depth']);
        $this->assertFalse($r['truncated']);
    }

    public function testForwardOntoBareConcept(): void
    {
        $r = $this->traverse(['factory' => 'expedition', 'startId' => $this->expeditionId, 'verb' => 'saison']);

        $this->assertSame(1, $r['totalFound']);
        $this->assertSame('ete_austral', $r['entities'][0]['concept']);
        $this->assertArrayNotHasKey('factory', $r['entities'][0]);
    }

    public function testBackwardFromTargetFactory(): void
    {
        $r = $this->traverse(['factory' => 'base', 'startId' => $this->baseId, 'verb' => 'atteint', 'direction' => 'backward']);

        $this->assertSame(1, $r['totalFound']);
        $this->assertSame($this->expeditionId, $r['entities'][0]['id']);
        $this->assertSame('expedition', $r['entities'][0]['factory']);
    }

    public function testDepthLimitAndLimitCap(): void
    {
        // atteint → base → (ravitaillePar is another verb, not followed): depth 1 only
        $r = $this->traverse(['factory' => 'expedition', 'startId' => $this->expeditionId, 'verb' => 'atteint', 'depth' => 5]);
        $this->assertSame(1, $r['totalFound']);

        $r = $this->traverse(['factory' => 'expedition', 'startId' => $this->expeditionId, 'verb' => 'atteint', 'limit' => 1]);
        $this->assertSame(1, $r['totalFound']);
        $this->assertFalse($r['truncated']);
    }

    public function testUnknownVerbIsExplicit(): void
    {
        $r = $this->traverse(['factory' => 'expedition', 'startId' => $this->expeditionId, 'verb' => 'inexistant']);
        $this->assertSame(0, $r['totalFound']);
        $this->assertStringContainsString('not a known concept', $r['note']);
    }

    public function testStartMustExistInNamedFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->traverse(['factory' => 'port', 'startId' => $this->expeditionId, 'verb' => 'partDe']);
    }
}
