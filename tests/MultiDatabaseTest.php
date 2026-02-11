<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\Driver\DatabaseDriverInterface;
use SandraCore\Driver\MySQLDriver;
use SandraCore\Driver\SQLiteDriver;
use SandraCore\EntityFactory;
use SandraCore\System;
use SandraCore\DatabaseAdapter;

/**
 * Multi-Database Support tests.
 * Theme: bibliotheque de livres (titre, auteur, genre, pages)
 */
class MultiDatabaseTest extends TestCase
{
    private static ?System $sqliteSystem = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset static state for each test
        System::$pdo = null;
        DatabaseAdapter::$driver = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset static state
        System::$pdo = null;
        DatabaseAdapter::$driver = null;
    }

    // --- SQLiteDriver unit tests ---

    public function testSQLiteDriverGetDsn(): void
    {
        $driver = new SQLiteDriver();
        $this->assertEquals('sqlite::memory:', $driver->getDsn('', ':memory:'));
        $this->assertEquals('sqlite:/tmp/test.db', $driver->getDsn('', '/tmp/test.db'));
    }

    public function testSQLiteDriverCreateTableSQL(): void
    {
        $driver = new SQLiteDriver();

        $conceptSQL = $driver->getCreateTableSQL('test_Concept', 'concept');
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $conceptSQL);
        $this->assertStringContainsString('UNIQUE (shortname)', $conceptSQL);
        $this->assertStringNotContainsString('ENGINE=', $conceptSQL);

        $tripletSQL = $driver->getCreateTableSQL('test_Triplets', 'triplet');
        $this->assertStringContainsString('UNIQUE (idConceptStart, idConceptLink, idConceptTarget)', $tripletSQL);

        $referenceSQL = $driver->getCreateTableSQL('test_References', 'reference');
        $this->assertStringContainsString('UNIQUE (idConcept, linkReferenced)', $referenceSQL);

        $storageSQL = $driver->getCreateTableSQL('test_Storage', 'storage');
        $this->assertStringContainsString('linkReferenced INTEGER NOT NULL PRIMARY KEY', $storageSQL);

        $configSQL = $driver->getCreateTableSQL('test_Config', 'config');
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $configSQL);
    }

    public function testSQLiteDriverUpsertSQL(): void
    {
        $driver = new SQLiteDriver();

        $refSQL = $driver->getUpsertReferenceSQL('test_Ref');
        $this->assertStringContainsString('ON CONFLICT(idConcept, linkReferenced) DO UPDATE', $refSQL);

        $tripletSQL = $driver->getUpsertTripletSQL('test_Trip');
        $this->assertStringContainsString('ON CONFLICT(idConceptStart, idConceptLink, idConceptTarget) DO UPDATE', $tripletSQL);

        $storageSQL = $driver->getUpsertStorageSQL('test_Store');
        $this->assertStringContainsString('INSERT OR REPLACE', $storageSQL);
    }

    public function testSQLiteDriverRandomAndCast(): void
    {
        $driver = new SQLiteDriver();
        $this->assertEquals('RANDOM()', $driver->getRandomOrderSQL());
        $this->assertEquals('CAST(col AS REAL)', $driver->getCastNumericSQL('col'));
    }

    public function testSQLiteDriverGetName(): void
    {
        $driver = new SQLiteDriver();
        $this->assertEquals('sqlite', $driver->getName());
    }

    // --- MySQLDriver unit tests ---

    public function testMySQLDriverGetDsn(): void
    {
        $driver = new MySQLDriver();
        $this->assertEquals('mysql:host=localhost;dbname=testdb', $driver->getDsn('localhost', 'testdb'));
    }

    public function testMySQLDriverCreateTableSQL(): void
    {
        $driver = new MySQLDriver();

        $conceptSQL = $driver->getCreateTableSQL('test_Concept', 'concept');
        $this->assertStringContainsString('AUTO_INCREMENT', $conceptSQL);
        $this->assertStringContainsString('ENGINE=InnoDB', $conceptSQL);

        $storageSQL = $driver->getCreateTableSQL('test_Storage', 'storage');
        $this->assertStringContainsString('ENGINE=MyISAM', $storageSQL);
    }

    public function testMySQLDriverUpsertSQL(): void
    {
        $driver = new MySQLDriver();

        $refSQL = $driver->getUpsertReferenceSQL('test_Ref');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $refSQL);
        $this->assertStringContainsString('LAST_INSERT_ID', $refSQL);

        $tripletSQL = $driver->getUpsertTripletSQL('test_Trip');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $tripletSQL);
    }

    public function testMySQLDriverRandomAndCast(): void
    {
        $driver = new MySQLDriver();
        $this->assertEquals('RAND()', $driver->getRandomOrderSQL());
        $this->assertEquals('CAST(col AS DECIMAL)', $driver->getCastNumericSQL('col'));
    }

    public function testMySQLDriverGetName(): void
    {
        $driver = new MySQLDriver();
        $this->assertEquals('mysql', $driver->getName());
    }

    // --- SQLite integration tests ---

    private function createSQLiteSystem(): System
    {
        $driver = new SQLiteDriver();
        return new System('lib', true, '', ':memory:', '', '', null, $driver);
    }

    public function testSQLiteIntegrationCreateBooks(): void
    {
        $system = $this->createSQLiteSystem();

        $bookFactory = new EntityFactory('livre', 'livresFile', $system);

        $book1 = $bookFactory->createNew([
            'titre' => 'Le Petit Prince',
            'auteur' => 'Antoine de Saint-Exupery',
            'genre' => 'Conte',
            'pages' => '93',
        ]);

        $book2 = $bookFactory->createNew([
            'titre' => 'Les Miserables',
            'auteur' => 'Victor Hugo',
            'genre' => 'Roman',
            'pages' => '1488',
        ]);

        $book3 = $bookFactory->createNew([
            'titre' => 'Germinal',
            'auteur' => 'Emile Zola',
            'genre' => 'Roman',
            'pages' => '591',
        ]);

        $this->assertEquals('Le Petit Prince', $book1->get('titre'));
        $this->assertEquals('Antoine de Saint-Exupery', $book1->get('auteur'));
        $this->assertEquals('Les Miserables', $book2->get('titre'));
        $this->assertEquals('Germinal', $book3->get('titre'));
    }

    public function testSQLiteIntegrationPopulateAndQuery(): void
    {
        $system = $this->createSQLiteSystem();

        $bookFactory = new EntityFactory('livre', 'livresFile', $system);

        $bookFactory->createNew([
            'titre' => 'Le Petit Prince',
            'auteur' => 'Antoine de Saint-Exupery',
            'genre' => 'Conte',
            'pages' => '93',
        ]);

        $bookFactory->createNew([
            'titre' => 'Les Miserables',
            'auteur' => 'Victor Hugo',
            'genre' => 'Roman',
            'pages' => '1488',
        ]);

        $bookFactory->createNew([
            'titre' => 'Germinal',
            'auteur' => 'Emile Zola',
            'genre' => 'Roman',
            'pages' => '591',
        ]);

        // Re-create factory and populate from DB
        $queryFactory = new EntityFactory('livre', 'livresFile', $system);
        $queryFactory->populateLocal();

        $entities = $queryFactory->getEntities();
        $this->assertCount(3, $entities);

        // Verify getAllWith works
        $results = $queryFactory->getAllWith('auteur', 'Victor Hugo');
        $this->assertNotNull($results);
        $this->assertCount(1, $results);

        $hugo = reset($results);
        $this->assertEquals('Les Miserables', $hugo->get('titre'));
    }

    public function testSQLiteRoundTrip(): void
    {
        $system = $this->createSQLiteSystem();

        $bookFactory = new EntityFactory('livre', 'livresFile', $system);

        $bookFactory->createNew([
            'titre' => 'Candide',
            'auteur' => 'Voltaire',
            'genre' => 'Philosophique',
            'pages' => '180',
        ]);

        $bookFactory->createNew([
            'titre' => 'Les Fleurs du Mal',
            'auteur' => 'Charles Baudelaire',
            'genre' => 'Poesie',
            'pages' => '252',
        ]);

        // Round-trip: create a fresh factory and repopulate
        $freshFactory = new EntityFactory('livre', 'livresFile', $system);
        $freshFactory->populateLocal();

        $entities = $freshFactory->getEntities();
        $this->assertCount(2, $entities);

        // Verify data integrity after round-trip
        $voltaire = $freshFactory->first('auteur', 'Voltaire');
        $this->assertNotNull($voltaire);
        $this->assertEquals('Candide', $voltaire->get('titre'));
        $this->assertEquals('Philosophique', $voltaire->get('genre'));
        $this->assertEquals('180', $voltaire->get('pages'));

        $baudelaire = $freshFactory->first('auteur', 'Charles Baudelaire');
        $this->assertNotNull($baudelaire);
        $this->assertEquals('Les Fleurs du Mal', $baudelaire->get('titre'));
    }

    public function testDriverInterfaceImplementation(): void
    {
        $mysql = new MySQLDriver();
        $sqlite = new SQLiteDriver();

        $this->assertInstanceOf(DatabaseDriverInterface::class, $mysql);
        $this->assertInstanceOf(DatabaseDriverInterface::class, $sqlite);
    }
}
