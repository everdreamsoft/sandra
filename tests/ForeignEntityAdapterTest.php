<?php

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 12:44
 */

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\Entity;
use SandraCore\ForeignEntityAdapter;

/**
 * Tests for ForeignEntityAdapter — pulls JSON from a URL and populates
 * entities in-memory, optionally fusing with a local Sandra factory.
 *
 * Historically this test pointed at a live Google Apps Script. It now
 * reads a local fixture file via `file://`, so it's deterministic and
 * network-free — safe to run as part of the default suite.
 */
final class ForeignEntityAdapterTest extends TestCase
{
    private function fixtureUrl(): string
    {
        return 'file://' . realpath(__DIR__ . '/fixtures/foreign-users.json');
    }

    public function testCanBeCreated(): void
    {
        $sandraToFlush = new \SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new \SandraCore\System('_phpUnit', true);

        $createdClass = new \SandraCore\ForeignEntityAdapter('http://www.google.com', '', $sandra);

        $this->assertInstanceOf(\SandraCore\ForeignEntityAdapter::class, $createdClass);
    }

    public function testPureForeignAdapter(): void
    {
        $sandraToFlush = new \SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new \SandraCore\System('_phpUnit', true);
        $factory = $sandra->factoryManager->create('personnelFactory', 'person', 'peopleFile');

        // The fixture is a top-level array, so the mainEntityPath is empty.
        $foreignAdapter = new ForeignEntityAdapter($this->fixtureUrl(), '', $sandra);

        $this->assertInstanceOf(\SandraCore\ForeignEntityAdapter::class, $foreignAdapter);
        $this->assertInstanceOf(\SandraCore\EntityFactory::class, $factory);

        $foreignAdapter->populate();

        $this->assertArrayHasKey('f:1', $foreignAdapter->entityArray, 'Failed to populate foreign entity');

        // Search for a known Visa value.
        $entityArray = $foreignAdapter->getAllWith('Visa', 'MR542');
        $noMatch = $foreignAdapter->getAllWith('Visa', 'MR599');     // non-existing value
        $invalidRef = $foreignAdapter->getAllWith('VisaX', 'MR542'); // non-existing reference

        $this->assertNull($invalidRef, 'Invalid reference returned a response');
        $this->assertNull($noMatch, 'Non-existing reference value returned something');
        $this->assertIsArray($entityArray, 'Search for Visa MR542 did not return an array');

        $entity = reset($entityArray);
        $this->assertInstanceOf(\SandraCore\ForeignEntity::class, $entity, 'Entity with Visa MR542 not found');
    }

    public function testForeignAndLocalFusion(): void
    {
        $sandraToFlush = new \SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new \SandraCore\System('_phpUnit', true);
        $factory = $sandra->factoryManager->create('personnelFactoryTestLocal', 'person', 'peopleFile');
        $factory->populateLocal();

        $foreignAdapter = new ForeignEntityAdapter($this->fixtureUrl(), '', $sandra);

        // limit=1 → only the first entity is populated
        $foreignAdapter->populate(1);

        $this->assertCount(1, $foreignAdapter->entityArray, 'The limitation of 1 in Foreign entity failed');

        $vocabulary = [
            'Visa' => 'visa',
            'Title' => 'Title',
            'Age' => 'age',
            'First Name' => 'firstname',
        ];

        $foreignAdapter->adaptToLocalVocabulary($vocabulary);
        $controlFactory = clone $factory;

        $factory->foreignPopulate($foreignAdapter, 2);

        $this->assertCount(2, $factory->entityArray, 'Our local factory should have 2 foreign concepts');

        $factory->setFuseForeignOnRef('Visa', 'visa', $vocabulary);
        $factory->getAllWith('Visa', 'MR542');
        $factory->fuseRemoteEntity();
        $factory->saveEntitiesNotInLocal();

        $factory->getAllWith('Visa', 'MR542');

        $controlFactory->populateLocal();
        $this->assertCount(2, $controlFactory->entityArray, 'The two foreign entities were not successfully saved');
    }

    public function testFusionAndUpdate(): void
    {
        $sandraToFlush = new \SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new \SandraCore\System('_phpUnit', true);

        $foreignAdapter = new ForeignEntityAdapter($this->fixtureUrl(), '', $sandra);

        $factory = $sandra->factoryManager->create('personnelFactoryTestLocal', 'person', 'peopleFile');
        $factory->populateLocal();
        $foreignAdapter->populate(5);

        $vocabulary = [
            'Visa' => 'visa',
            'Title' => 'Title',
            'Age' => 'age',
            'First Name' => 'firstname',
        ];

        $foreignAdapter->adaptToLocalVocabulary($vocabulary);
        $factory->foreignPopulate($foreignAdapter, 5);

        $factory->mergeEntities('Visa', 'visa');
        $factory->fuseRemoteEntity(true);
        $entities = $factory->getEntities();
        $firstEntity = reset($entities);

        // Leanne Graham's Age in the fixture is 55 — verify fusion applied it.
        $this->assertEquals(55, $firstEntity->getReference('age')->refValue);
    }
}
