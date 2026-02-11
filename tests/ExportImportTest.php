<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Export\CsvExporter;
use SandraCore\Import\CsvImporter;
use SandraCore\Import\ImportResult;
use SandraCore\Exception\SandraException;

final class ExportImportTest extends SandraTestCase
{
    // --- Export tests ---

    public function testExportBasic(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Mars', 'type' => 'rocky']);
        $factory->createNew(['name' => 'Jupiter', 'type' => 'gas']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory, ['name', 'type']);

        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(3, $lines); // header + 2 rows
        $this->assertStringContainsString('name', $lines[0]);
        $this->assertStringContainsString('Mars', $csv);
        $this->assertStringContainsString('Jupiter', $csv);
    }

    public function testExportSpecificColumns(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Mars', 'type' => 'rocky']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory, ['name']);

        $this->assertStringContainsString('name', $csv);
        $this->assertStringContainsString('Mars', $csv);
        // type should not be in the output since we only asked for 'name'
        $this->assertStringNotContainsString('rocky', $csv);
    }

    public function testExportAutoDetectColumns(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Earth', 'type' => 'rocky']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory);

        $this->assertStringContainsString('name', $csv);
        $this->assertStringContainsString('Earth', $csv);
        // creationTimestamp should be filtered out
        $this->assertStringNotContainsString('creationTimestamp', $csv);
    }

    public function testExportEmptyFactory(): void
    {
        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory, ['name']);

        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(1, $lines); // header only
    }

    public function testExportNoHeader(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Mars']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter(',', '"', false);
        $csv = $exporter->export($factory, ['name']);

        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(1, $lines); // no header, just data
        $this->assertStringContainsString('Mars', $lines[0]);
    }

    public function testExportCustomDelimiter(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Mars', 'type' => 'rocky']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter(';');
        $csv = $exporter->export($factory, ['name', 'type']);

        $this->assertStringContainsString(';', $csv);
    }

    public function testExportSpecialChars(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Planet "X"', 'type' => 'a,b']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory, ['name', 'type']);

        // CSV escapes quotes as "" and wraps fields with special chars
        $this->assertStringContainsString('Planet ""X""', $csv);
        $this->assertStringContainsString('"a,b"', $csv);
    }

    public function testExportNullValues(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->createNew(['name' => 'Mars']);

        $factory = $this->createPopulatedFactory('planet', 'solarSystemFile');
        $exporter = new CsvExporter();
        $csv = $exporter->export($factory, ['name', 'nonexistent']);

        $this->assertStringContainsString('Mars', $csv);
    }

    // --- Import tests ---

    public function testImportBasic(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $csv = "name,type\nMars,rocky\nJupiter,gas\n";

        $result = $importer->importString($factory, $csv);

        $this->assertEquals(2, $result->getCreatedCount());
        $this->assertTrue($result->isFullySuccessful());
    }

    public function testImportHeaderMapping(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $importer->setColumnMapping(['Planet Name' => 'name', 'Planet Type' => 'type']);
        $csv = "Planet Name,Planet Type\nMars,rocky\n";

        $result = $importer->importString($factory, $csv);

        $this->assertEquals(1, $result->getCreatedCount());
        $entity = $result->getCreated()[0];
        $this->assertEquals('Mars', $entity->get('name'));
        $this->assertEquals('rocky', $entity->get('type'));
    }

    public function testImportColumnIndexMapping(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter(',', '"', false);
        $importer->setColumnMapping([0 => 'name', 1 => 'type']);
        $csv = "Mars,rocky\n";

        $result = $importer->importString($factory, $csv);

        $this->assertEquals(1, $result->getCreatedCount());
        $entity = $result->getCreated()[0];
        $this->assertEquals('Mars', $entity->get('name'));
    }

    public function testImportSkipsEmptyRows(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $csv = "name\nMars\n\nJupiter\n";

        $result = $importer->importString($factory, $csv);

        $this->assertEquals(2, $result->getCreatedCount());
    }

    public function testImportResultCounts(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $csv = "name\nMars\nJupiter\nVenus\n";

        $result = $importer->importString($factory, $csv);

        $this->assertEquals(3, $result->getTotalRows());
        $this->assertEquals(3, $result->getCreatedCount());
        $this->assertEquals(0, $result->getErrorCount());
        $this->assertFalse($result->hasErrors());
    }

    public function testImportValidationErrorsInResult(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation(['name' => ['required']]);
        $importer = new CsvImporter();
        $csv = "name,type\nMars,rocky\n,gas\n";

        $result = $importer->importString($factory, $csv);

        // "Mars" should succeed, empty name should fail validation
        $this->assertEquals(1, $result->getCreatedCount());
        $this->assertEquals(1, $result->getErrorCount());
        $this->assertTrue($result->hasErrors());
        $this->assertFalse($result->isFullySuccessful());
    }

    public function testImportPreservesData(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $csv = "name,type\nMars,rocky\n";

        $result = $importer->importString($factory, $csv);
        $entity = $result->getCreated()[0];

        $this->assertEquals('Mars', $entity->get('name'));
        $this->assertEquals('rocky', $entity->get('type'));
    }

    public function testImportFile(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $tmpFile = tempnam(sys_get_temp_dir(), 'sandra_test_');
        file_put_contents($tmpFile, "name\nMars\n");

        $importer = new CsvImporter();
        $result = $importer->importFile($factory, $tmpFile);

        $this->assertEquals(1, $result->getCreatedCount());

        unlink($tmpFile);
    }

    public function testImportNonexistentFileThrows(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();

        $this->expectException(SandraException::class);
        $importer->importFile($factory, '/nonexistent/file.csv');
    }

    // --- Round-trip tests ---

    public function testRoundTripExportThenImport(): void
    {
        // Create source data
        $sourceFactory = $this->createFactory('planet', 'solarSystemFile');
        $sourceFactory->createNew(['name' => 'Mars', 'type' => 'rocky']);
        $sourceFactory->createNew(['name' => 'Jupiter', 'type' => 'gas']);

        $sourceFactory = $this->createPopulatedFactory('planet', 'solarSystemFile');

        // Export
        $exporter = new CsvExporter();
        $csv = $exporter->export($sourceFactory, ['name', 'type']);

        // Flush and import into a new factory
        $flusher = new \SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($flusher);
        $this->system = new \SandraCore\System('phpUnit_', true);

        $targetFactory = $this->createFactory('planet', 'solarSystemFile');
        $importer = new CsvImporter();
        $result = $importer->importString($targetFactory, $csv);

        $this->assertEquals(2, $result->getCreatedCount());
        $this->assertTrue($result->isFullySuccessful());

        // Verify data matches
        $names = array_map(fn($e) => $e->get('name'), $result->getCreated());
        sort($names);
        $this->assertEquals(['Jupiter', 'Mars'], $names);
    }
}
