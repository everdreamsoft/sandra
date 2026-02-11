<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Api\ApiHandler;
use SandraCore\Api\ApiRequest;
use SandraCore\Api\ApiResponse;
use SandraCore\EntityFactory;

/**
 * REST API tests.
 * Theme: gestion de restaurant (plats, prix, categorie, disponibilite)
 */
class RestApiTest extends SandraTestCase
{
    private ApiHandler $api;
    private EntityFactory $plats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plats = new EntityFactory('plat', 'platsFile', $this->system);

        $this->plats->createNew([
            'nom' => 'Pizza Margherita',
            'prix' => '12.50',
            'categorie' => 'Pizza',
            'disponibilite' => 'oui',
        ]);

        $this->plats->createNew([
            'nom' => 'Salade Cesar',
            'prix' => '9.00',
            'categorie' => 'Salade',
            'disponibilite' => 'oui',
        ]);

        $this->plats->createNew([
            'nom' => 'Pizza Quattro Formaggi',
            'prix' => '14.00',
            'categorie' => 'Pizza',
            'disponibilite' => 'non',
        ]);

        // Repopulate factory
        $this->plats = new EntityFactory('plat', 'platsFile', $this->system);
        $this->plats->populateLocal();

        $this->api = new ApiHandler($this->system);
        $this->api->register('plats', $this->plats, [
            'searchable' => ['nom', 'categorie'],
        ]);
    }

    public function testGetListPlats(): void
    {
        $request = new ApiRequest('GET', '/plats');
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertEquals(3, $data['total']);
        $this->assertCount(3, $data['items']);
    }

    public function testGetListWithPagination(): void
    {
        $request = new ApiRequest('GET', '/plats', ['limit' => '2', 'offset' => '1']);
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(2, $data['items']);
        $this->assertEquals(3, $data['total']);
        $this->assertEquals(2, $data['limit']);
        $this->assertEquals(1, $data['offset']);
    }

    public function testGetPlatById(): void
    {
        $entities = $this->plats->getEntities();
        $firstEntity = reset($entities);
        $conceptId = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats/$conceptId");
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('refs', $data);
        $this->assertEquals((int)$conceptId, $data['id']);
    }

    public function testGetPlatNotFound(): void
    {
        $request = new ApiRequest('GET', '/plats/999999');
        $response = $this->api->handle($request);

        $this->assertEquals(404, $response->getStatus());
        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
    }

    public function testPostCreatePlat(): void
    {
        $request = new ApiRequest('POST', '/plats', [], [
            'nom' => 'Tiramisu',
            'prix' => '7.50',
            'categorie' => 'Dessert',
            'disponibilite' => 'oui',
        ]);
        $response = $this->api->handle($request);

        $this->assertEquals(201, $response->getStatus());
        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('refs', $data);
        $this->assertEquals('Tiramisu', $data['refs']['nom']);
    }

    public function testPostValidationError(): void
    {
        // Set up validation on a new factory
        $validatedFactory = new EntityFactory('plat_v', 'platsVFile', $this->system);
        $validatedFactory->setValidation([
            'nom' => ['required'],
            'prix' => ['required', 'numeric'],
        ]);

        $validatedApi = new ApiHandler($this->system);
        $validatedApi->register('plats_v', $validatedFactory);

        $request = new ApiRequest('POST', '/plats_v', [], [
            'prix' => 'not_a_number',
        ]);
        $response = $validatedApi->handle($request);

        $this->assertEquals(422, $response->getStatus());
        $this->assertFalse($response->isSuccess());
    }

    public function testPutUpdatePlat(): void
    {
        $entities = $this->plats->getEntities();
        $firstEntity = reset($entities);
        $conceptId = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('PUT', "/plats/$conceptId", [], [
            'prix' => '15.00',
        ]);
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertEquals('15.00', $data['refs']['prix']);
    }

    public function testDeletePlat(): void
    {
        $entities = $this->plats->getEntities();
        $firstEntity = reset($entities);
        $conceptId = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('DELETE', "/plats/$conceptId");
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['deleted']);
        $this->assertEquals((int)$conceptId, $data['id']);
    }

    public function testSearchPlats(): void
    {
        $request = new ApiRequest('GET', '/plats', ['search' => 'pizza']);
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('items', $data);
        $this->assertGreaterThanOrEqual(1, count($data['items']));
    }

    public function testRouteNotFound(): void
    {
        $request = new ApiRequest('GET', '/desserts');
        $response = $this->api->handle($request);

        $this->assertEquals(404, $response->getStatus());
        $this->assertStringContainsString('not found', $response->getError());
    }

    public function testMethodNotAllowed(): void
    {
        $request = new ApiRequest('PATCH', '/plats');
        $response = $this->api->handle($request);

        $this->assertEquals(405, $response->getStatus());
    }

    public function testReadOnlyFactoryRejectsWrite(): void
    {
        $readOnlyFactory = new EntityFactory('plat_ro', 'platsROFile', $this->system);
        $readOnlyFactory->createNew(['nom' => 'Test', 'prix' => '1.00']);
        $readOnlyFactory = new EntityFactory('plat_ro', 'platsROFile', $this->system);
        $readOnlyFactory->populateLocal();

        $roApi = new ApiHandler($this->system);
        $roApi->register('readonly', $readOnlyFactory, [
            'read' => true,
            'create' => false,
            'update' => false,
            'delete' => false,
        ]);

        // POST should be rejected
        $request = new ApiRequest('POST', '/readonly', [], ['nom' => 'New']);
        $response = $roApi->handle($request);
        $this->assertEquals(405, $response->getStatus());

        // PUT should be rejected
        $entities = $readOnlyFactory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('PUT', "/readonly/$id", [], ['nom' => 'Updated']);
        $response = $roApi->handle($request);
        $this->assertEquals(405, $response->getStatus());

        // DELETE should be rejected
        $request = new ApiRequest('DELETE', "/readonly/$id");
        $response = $roApi->handle($request);
        $this->assertEquals(405, $response->getStatus());
    }

    public function testMultipleFactoriesRegistered(): void
    {
        $desserts = new EntityFactory('dessert', 'dessertsFile', $this->system);
        $desserts->createNew(['nom' => 'Creme Brulee', 'prix' => '8.00']);
        $desserts = new EntityFactory('dessert', 'dessertsFile', $this->system);
        $desserts->populateLocal();

        $this->api->register('desserts', $desserts);

        $platResponse = $this->api->handle(new ApiRequest('GET', '/plats'));
        $dessertResponse = $this->api->handle(new ApiRequest('GET', '/desserts'));

        $this->assertEquals(200, $platResponse->getStatus());
        $this->assertEquals(200, $dessertResponse->getStatus());
        $this->assertEquals(3, $platResponse->getData()['total']);
        $this->assertEquals(1, $dessertResponse->getData()['total']);
    }

    public function testEmptyListReturns200(): void
    {
        $emptyFactory = new EntityFactory('vide', 'videFile', $this->system);
        $emptyFactory->populateLocal();

        $emptyApi = new ApiHandler($this->system);
        $emptyApi->register('vide', $emptyFactory);

        $request = new ApiRequest('GET', '/vide');
        $response = $emptyApi->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertEquals(0, $data['total']);
        $this->assertEmpty($data['items']);
    }

    public function testJsonResponseFormat(): void
    {
        $request = new ApiRequest('GET', '/plats');
        $response = $this->api->handle($request);

        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertEquals(200, $decoded['status']);
    }

    // --- Brother Entity Tests ---

    private function createBrotherSetup(): array
    {
        $factory = new EntityFactory('plat_b', 'platsBFile', $this->system);

        $pizza = $factory->createNew([
            'nom' => 'Pizza Margherita',
            'prix' => '12.50',
        ]);
        $pizza->setBrotherEntity('categoriePlat', 'Pizza', ['ordering' => '1']);

        $salade = $factory->createNew([
            'nom' => 'Salade Cesar',
            'prix' => '9.00',
        ]);
        $salade->setBrotherEntity('categoriePlat', 'Salade', ['ordering' => '2']);

        // Repopulate
        $factory = new EntityFactory('plat_b', 'platsBFile', $this->system);
        $factory->populateLocal();

        $api = new ApiHandler($this->system);
        $api->register('plats_b', $factory, [
            'brothers' => ['categoriePlat'],
        ]);

        return [$api, $factory];
    }

    public function testGetPlatWithBrothers(): void
    {
        [$api, $factory] = $this->createBrotherSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_b/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('brothers', $data);
        $this->assertArrayHasKey('categoriePlat', $data['brothers']);
        $this->assertCount(1, $data['brothers']['categoriePlat']);
        $this->assertEquals('Pizza', $data['brothers']['categoriePlat'][0]['target']);
    }

    public function testGetListWithBrothers(): void
    {
        [$api, $factory] = $this->createBrotherSetup();

        $request = new ApiRequest('GET', '/plats_b');
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(2, $data['items']);

        foreach ($data['items'] as $item) {
            $this->assertArrayHasKey('brothers', $item);
            $this->assertArrayHasKey('categoriePlat', $item['brothers']);
        }
    }

    public function testPostCreatePlatWithBrothers(): void
    {
        $factory = new EntityFactory('plat_bp', 'platsBPFile', $this->system);
        $factory->populateLocal();

        $api = new ApiHandler($this->system);
        $api->register('plats_bp', $factory, [
            'brothers' => ['categoriePlat'],
        ]);

        $request = new ApiRequest('POST', '/plats_bp', [], [
            'nom' => 'Risotto',
            'prix' => '16.00',
            'brothers' => [
                'categoriePlat' => [
                    ['target' => 'Italienne', 'refs' => ['ordering' => '3']],
                ],
            ],
        ]);
        $response = $api->handle($request);

        $this->assertEquals(201, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('brothers', $data);
        $this->assertCount(1, $data['brothers']['categoriePlat']);
        $this->assertEquals('Italienne', $data['brothers']['categoriePlat'][0]['target']);
    }

    public function testPutUpdateAddBrother(): void
    {
        [$api, $factory] = $this->createBrotherSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('PUT', "/plats_b/$id", [], [
            'prix' => '15.00',
            'brothers' => [
                'categoriePlat' => [
                    ['target' => 'Italienne', 'refs' => []],
                ],
            ],
        ]);
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertEquals('15.00', $data['refs']['prix']);
        $this->assertArrayHasKey('brothers', $data);
        // Should now have 2 brothers: original Pizza + new Italienne
        $this->assertCount(2, $data['brothers']['categoriePlat']);
    }

    public function testGetWithoutBrothersOptionExcludesBrothers(): void
    {
        // The main $this->plats factory has no brothers option
        $entities = $this->plats->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats/$id");
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayNotHasKey('brothers', $data);
    }

    public function testBrothersWithReferences(): void
    {
        [$api, $factory] = $this->createBrotherSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_b/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $brother = $data['brothers']['categoriePlat'][0];
        $this->assertArrayHasKey('refs', $brother);
        $this->assertEquals('1', $brother['refs']['ordering']);
        $this->assertArrayHasKey('targetConceptId', $brother);
        $this->assertIsInt($brother['targetConceptId']);
    }

    public function testGetPlatBrothersMultipleEntries(): void
    {
        $factory = new EntityFactory('plat_bm', 'platsBMFile', $this->system);

        $plat = $factory->createNew([
            'nom' => 'Pizza Speciale',
            'prix' => '18.00',
        ]);
        $plat->setBrotherEntity('categoriePlat', 'Pizza', []);
        $plat->setBrotherEntity('categoriePlat', 'Italienne', []);
        $plat->setBrotherEntity('categoriePlat', 'Speciale', []);

        // Repopulate
        $factory = new EntityFactory('plat_bm', 'platsBMFile', $this->system);
        $factory->populateLocal();

        $api = new ApiHandler($this->system);
        $api->register('plats_bm', $factory, [
            'brothers' => ['categoriePlat'],
        ]);

        $entities = $factory->getEntities();
        $entity = reset($entities);
        $id = $entity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_bm/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(3, $data['brothers']['categoriePlat']);

        $targets = array_column($data['brothers']['categoriePlat'], 'target');
        $this->assertContains('Pizza', $targets);
        $this->assertContains('Italienne', $targets);
        $this->assertContains('Speciale', $targets);
    }

    public function testPostBrothersOnReadOnlyRejectsWrite(): void
    {
        $factory = new EntityFactory('plat_bro', 'platsBROFile', $this->system);
        $factory->createNew(['nom' => 'Test', 'prix' => '1.00']);
        $factory = new EntityFactory('plat_bro', 'platsBROFile', $this->system);
        $factory->populateLocal();

        $roApi = new ApiHandler($this->system);
        $roApi->register('readonly_b', $factory, [
            'read' => true,
            'create' => false,
            'update' => false,
            'delete' => false,
            'brothers' => ['categoriePlat'],
        ]);

        $request = new ApiRequest('POST', '/readonly_b', [], [
            'nom' => 'New',
            'brothers' => [
                'categoriePlat' => [
                    ['target' => 'Pizza', 'refs' => []],
                ],
            ],
        ]);
        $response = $roApi->handle($request);
        $this->assertEquals(405, $response->getStatus());
    }

    // --- Joined Entity Tests ---

    private function createJoinedSetup(): array
    {
        // Create ingredients factory with some ingredients
        $ingredients = new EntityFactory('ingredient_j', 'ingredientsJFile', $this->system);
        $ingredients->createNew(['nom' => 'Tomate', 'type' => 'legume']);
        $ingredients->createNew(['nom' => 'Mozzarella', 'type' => 'fromage']);
        $ingredients->createNew(['nom' => 'Basilic', 'type' => 'herbe']);

        // Repopulate ingredients to get IDs
        $ingredients = new EntityFactory('ingredient_j', 'ingredientsJFile', $this->system);
        $ingredients->populateLocal();

        $ingredientEntities = $ingredients->getEntities();
        $ingredientIds = [];
        foreach ($ingredientEntities as $entity) {
            $ingredientIds[$entity->get('nom')] = (int)$entity->subjectConcept->idConcept;
        }

        // Create plats factory and link to ingredients
        $platsFactory = new EntityFactory('plat_j', 'platsJFile', $this->system);
        $platsFactory->joinFactory('composeDe', $ingredients);

        $pizza = $platsFactory->createNew(['nom' => 'Pizza Margherita', 'prix' => '12.50']);
        // Link pizza to Tomate and Mozzarella
        foreach ($ingredientEntities as $ingEntity) {
            $nom = $ingEntity->get('nom');
            if ($nom === 'Tomate' || $nom === 'Mozzarella') {
                $pizza->setJoinedEntity('composeDe', $ingEntity, []);
            }
        }

        $salade = $platsFactory->createNew(['nom' => 'Salade Caprese', 'prix' => '9.00']);
        // Link salade to Tomate, Mozzarella, Basilic
        foreach ($ingredientEntities as $ingEntity) {
            $salade->setJoinedEntity('composeDe', $ingEntity, []);
        }

        // Repopulate plats
        $platsFactory = new EntityFactory('plat_j', 'platsJFile', $this->system);
        $platsFactory->populateLocal();

        // Repopulate ingredients (fresh instance for API registration)
        $ingredients = new EntityFactory('ingredient_j', 'ingredientsJFile', $this->system);
        $ingredients->populateLocal();

        $api = new ApiHandler($this->system);
        $api->register('plats_j', $platsFactory, [
            'joined' => ['composeDe' => $ingredients],
        ]);

        return [$api, $platsFactory, $ingredients, $ingredientIds];
    }

    public function testGetPlatWithJoined(): void
    {
        [$api, $factory, $ingredients, $ingredientIds] = $this->createJoinedSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_j/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('joined', $data);
        $this->assertArrayHasKey('composeDe', $data['joined']);
        $this->assertGreaterThanOrEqual(1, count($data['joined']['composeDe']));
    }

    public function testGetListWithJoined(): void
    {
        [$api, $factory] = $this->createJoinedSetup();

        $request = new ApiRequest('GET', '/plats_j');
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(2, $data['items']);

        foreach ($data['items'] as $item) {
            $this->assertArrayHasKey('joined', $item);
            $this->assertArrayHasKey('composeDe', $item['joined']);
        }
    }

    public function testPostCreatePlatWithJoined(): void
    {
        // Create ingredients factory
        $ingredients = new EntityFactory('ingredient_jp', 'ingredientsJPFile', $this->system);
        $ingredients->createNew(['nom' => 'Tomate', 'type' => 'legume']);
        $ingredients->createNew(['nom' => 'Basilic', 'type' => 'herbe']);

        $ingredients = new EntityFactory('ingredient_jp', 'ingredientsJPFile', $this->system);
        $ingredients->populateLocal();

        $ingredientIds = [];
        foreach ($ingredients->getEntities() as $entity) {
            $ingredientIds[] = (int)$entity->subjectConcept->idConcept;
        }

        $platsFactory = new EntityFactory('plat_jp', 'platsJPFile', $this->system);
        $platsFactory->populateLocal();

        $api = new ApiHandler($this->system);
        $api->register('plats_jp', $platsFactory, [
            'joined' => ['composeDe' => $ingredients],
        ]);

        $request = new ApiRequest('POST', '/plats_jp', [], [
            'nom' => 'Bruschetta',
            'prix' => '8.00',
            'joined' => [
                'composeDe' => $ingredientIds,
            ],
        ]);
        $response = $api->handle($request);

        $this->assertEquals(201, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('joined', $data);
        $this->assertCount(2, $data['joined']['composeDe']);
    }

    public function testPutUpdateAddJoined(): void
    {
        [$api, $factory, $ingredients, $ingredientIds] = $this->createJoinedSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        // Add Basilic to the first plat via PUT
        $basilicId = $ingredientIds['Basilic'];

        $request = new ApiRequest('PUT', "/plats_j/$id", [], [
            'prix' => '15.00',
            'joined' => [
                'composeDe' => [$basilicId],
            ],
        ]);
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertEquals('15.00', $data['refs']['prix']);
        $this->assertArrayHasKey('joined', $data);
        // Should now have 3 joined ingredients: Tomate, Mozzarella + Basilic
        $this->assertCount(3, $data['joined']['composeDe']);
    }

    public function testGetWithoutJoinedOptionExcludesJoined(): void
    {
        // The main $this->plats factory has no joined option
        $entities = $this->plats->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats/$id");
        $response = $this->api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayNotHasKey('joined', $data);
    }

    public function testGetJoinedMultipleEntities(): void
    {
        [$api, $factory] = $this->createJoinedSetup();

        // Find the Salade Caprese (linked to all 3 ingredients)
        $saladeEntity = null;
        foreach ($factory->getEntities() as $entity) {
            if ($entity->get('nom') === 'Salade Caprese') {
                $saladeEntity = $entity;
                break;
            }
        }
        $this->assertNotNull($saladeEntity);
        $id = $saladeEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_j/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(3, $data['joined']['composeDe']);
    }

    public function testJoinedEntityRefsAreSerialized(): void
    {
        [$api, $factory] = $this->createJoinedSetup();

        $entities = $factory->getEntities();
        $firstEntity = reset($entities);
        $id = $firstEntity->subjectConcept->idConcept;

        $request = new ApiRequest('GET', "/plats_j/$id");
        $response = $api->handle($request);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();

        foreach ($data['joined']['composeDe'] as $joinedItem) {
            $this->assertArrayHasKey('id', $joinedItem);
            $this->assertIsInt($joinedItem['id']);
            $this->assertArrayHasKey('refs', $joinedItem);
            $this->assertIsArray($joinedItem['refs']);
            $this->assertArrayHasKey('nom', $joinedItem['refs']);
        }
    }
}
