<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Search\BasicSearch;
use SandraCore\Search\SearchInterface;

/**
 * Full-Text Search tests.
 * Theme: annuaire de contacts (nom, prenom, ville, email)
 */
class FullTextSearchTest extends SandraTestCase
{
    private EntityFactory $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contacts = new EntityFactory('contact', 'contactsFile', $this->system);

        $this->contacts->createNew([
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'ville' => 'Paris',
            'email' => 'jean.dupont@email.fr',
        ]);

        $this->contacts->createNew([
            'nom' => 'Martin',
            'prenom' => 'Marie',
            'ville' => 'Lyon',
            'email' => 'marie.martin@email.fr',
        ]);

        $this->contacts->createNew([
            'nom' => 'Dupont',
            'prenom' => 'Pierre',
            'ville' => 'Marseille',
            'email' => 'pierre.dupont@email.fr',
        ]);

        $this->contacts->createNew([
            'nom' => 'Bernard',
            'prenom' => 'Sophie',
            'ville' => 'Paris',
            'email' => 'sophie.bernard@email.fr',
        ]);

        $this->contacts->createNew([
            'nom' => 'Lefevre',
            'prenom' => 'Jean-Paul',
            'ville' => 'Toulouse',
            'email' => 'jp.lefevre@email.fr',
        ]);

        // Repopulate factory from DB for searching
        $this->contacts = new EntityFactory('contact', 'contactsFile', $this->system);
        $this->contacts->populateLocal();
    }

    public function testSearchFindsContact(): void
    {
        $search = new BasicSearch();
        $results = $search->search($this->contacts, 'Dupont');

        $this->assertNotEmpty($results);
        $names = array_map(fn($e) => $e->get('nom'), $results);
        $this->assertTrue(in_array('Dupont', $names));
    }

    public function testSearchNoResults(): void
    {
        $search = new BasicSearch();
        $results = $search->search($this->contacts, 'xyz123nonexistent');

        $this->assertEmpty($results);
    }

    public function testSearchCaseInsensitive(): void
    {
        $search = new BasicSearch();

        $resultsLower = $search->search($this->contacts, 'dupont');
        $resultsUpper = $search->search($this->contacts, 'DUPONT');
        $resultsMixed = $search->search($this->contacts, 'DuPoNt');

        $this->assertNotEmpty($resultsLower);
        $this->assertCount(count($resultsLower), $resultsUpper);
        $this->assertCount(count($resultsLower), $resultsMixed);
    }

    public function testSearchPartialMatch(): void
    {
        $search = new BasicSearch();
        $results = $search->search($this->contacts, 'Dup');

        $this->assertNotEmpty($results);
        $names = array_map(fn($e) => $e->get('nom'), $results);
        foreach ($names as $name) {
            $this->assertStringContainsString('Dup', $name);
        }
    }

    public function testSearchMultiWords(): void
    {
        $search = new BasicSearch();
        // "Jean Paris" should find Jean Dupont who lives in Paris
        $results = $search->search($this->contacts, 'Jean Paris');

        $this->assertNotEmpty($results);
        $first = $results[0];
        $this->assertEquals('Jean', $first->get('prenom'));
        $this->assertEquals('Paris', $first->get('ville'));
    }

    public function testSearchByFieldSpecific(): void
    {
        $search = new BasicSearch();
        // Search only in "ville" field
        $results = $search->searchByField($this->contacts, 'ville', 'Paris');

        $this->assertNotEmpty($results);
        foreach ($results as $entity) {
            $this->assertEquals('Paris', $entity->get('ville'));
        }
        $this->assertCount(2, $results); // Jean Dupont and Sophie Bernard
    }

    public function testSearchLimitRespected(): void
    {
        $search = new BasicSearch();
        // Search for email domain which matches all contacts
        $results = $search->search($this->contacts, 'email', 2);

        $this->assertLessThanOrEqual(2, count($results));
    }

    public function testSearchEmptyQuery(): void
    {
        $search = new BasicSearch();
        $results = $search->search($this->contacts, '');

        $this->assertEmpty($results);

        $results = $search->search($this->contacts, '   ');
        $this->assertEmpty($results);
    }

    public function testSearchRelevanceOrder(): void
    {
        $search = new BasicSearch();
        // "Martin" is an exact match for nom, should rank higher than partial matches
        $results = $search->search($this->contacts, 'Martin');

        $this->assertNotEmpty($results);
        // The first result should be the one with exact name match
        $this->assertEquals('Martin', $results[0]->get('nom'));
    }

    public function testFactorySearchShortcut(): void
    {
        $results = $this->contacts->search('Dupont');

        $this->assertNotEmpty($results);
        $names = array_map(fn($e) => $e->get('nom'), $results);
        foreach ($names as $name) {
            $this->assertEquals('Dupont', $name);
        }
    }
}
