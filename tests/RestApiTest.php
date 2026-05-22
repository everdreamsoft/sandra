<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Api\ApiHandler;
use SandraCore\Api\ApiRequest;
use SandraCore\Api\ApiResponse;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;

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

    // --- Storage on entities (long-text payload) ---

    public function testPostCreatePlatWithStorage(): void
    {
        $longText = "Recette détaillée:\n- pâte fine\n- tomate San Marzano\n- mozza di bufala";
        $request = new ApiRequest('POST', '/plats', [], [
            'nom' => 'Pizza Napoletana',
            'prix' => '13.50',
            'categorie' => 'Pizza',
            'disponibilite' => 'oui',
            'storage' => $longText,
        ]);
        $response = $this->api->handle($request);

        $this->assertEquals(201, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('storage', $data, 'Response should echo storage when provided');
        $this->assertSame($longText, $data['storage']);
        $this->assertArrayNotHasKey('storage', $data['refs'], 'storage must NOT leak into refs');
    }

    public function testPostWithoutStorageOmitsField(): void
    {
        $request = new ApiRequest('POST', '/plats', [], [
            'nom' => 'Penne Arrabbiata',
            'prix' => '11.00',
            'categorie' => 'Pasta',
            'disponibilite' => 'oui',
        ]);
        $response = $this->api->handle($request);

        $this->assertEquals(201, $response->getStatus());
        $this->assertArrayNotHasKey('storage', $response->getData());
    }

    public function testGetByIdWithIncludeStorageReturnsPayload(): void
    {
        // Seed an entity with storage
        $created = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Risotto',
            'prix' => '13.00',
            'categorie' => 'Pasta',
            'disponibilite' => 'oui',
            'storage' => 'long recipe text',
        ]));
        $id = $created->getData()['id'];

        // Fresh handler (mimics a new request, factory not yet populated for this id)
        $freshFactory = new EntityFactory('plat', 'platsFile', $this->system);
        $freshApi = new ApiHandler($this->system);
        $freshApi->register('plats', $freshFactory);

        $with = $freshApi->handle(new ApiRequest('GET', "/plats/$id", ['include_storage' => 'true']));
        $this->assertEquals(200, $with->getStatus());
        $this->assertSame('long recipe text', $with->getData()['storage']);

        $without = $freshApi->handle(new ApiRequest('GET', "/plats/$id"));
        $this->assertArrayNotHasKey('storage', $without->getData(), 'storage is opt-in via ?include_storage=true');
    }

    public function testGetListWithIncludeStorageBatchFetches(): void
    {
        // Two entities, only one with storage
        $a = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Tagliatelle', 'prix' => '12.00', 'categorie' => 'Pasta', 'disponibilite' => 'oui',
            'storage' => 'with storage',
        ]));
        $b = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Gnocchi', 'prix' => '11.50', 'categorie' => 'Pasta', 'disponibilite' => 'oui',
        ]));
        $idA = $a->getData()['id'];
        $idB = $b->getData()['id'];

        $freshFactory = new EntityFactory('plat', 'platsFile', $this->system);
        $freshApi = new ApiHandler($this->system);
        $freshApi->register('plats', $freshFactory);

        $list = $freshApi->handle(new ApiRequest('GET', '/plats', ['include_storage' => 'true']));
        $this->assertEquals(200, $list->getStatus());

        $byId = [];
        foreach ($list->getData()['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertArrayHasKey('storage', $byId[$idA]);
        $this->assertSame('with storage', $byId[$idA]['storage']);
        $this->assertArrayHasKey('storage', $byId[$idB]);
        $this->assertNull($byId[$idB]['storage'], 'Entity without storage row should serialize as null');
    }

    public function testPutReplaceStorage(): void
    {
        $created = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Carbonara', 'prix' => '12.00', 'categorie' => 'Pasta', 'disponibilite' => 'oui',
            'storage' => 'v1',
        ]));
        $id = $created->getData()['id'];

        $put = $this->api->handle(new ApiRequest('PUT', "/plats/$id", [], [
            'storage' => 'v2 — replaced',
        ]));
        $this->assertEquals(200, $put->getStatus());
        $this->assertSame('v2 — replaced', $put->getData()['storage']);

        // Verify persisted
        $freshFactory = new EntityFactory('plat', 'platsFile', $this->system);
        $freshApi = new ApiHandler($this->system);
        $freshApi->register('plats', $freshFactory);
        $got = $freshApi->handle(new ApiRequest('GET', "/plats/$id", ['include_storage' => '1']));
        $this->assertSame('v2 — replaced', $got->getData()['storage']);
    }

    public function testPutClearStorageWithEmptyString(): void
    {
        $created = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Lasagne', 'prix' => '14.00', 'categorie' => 'Pasta', 'disponibilite' => 'oui',
            'storage' => 'to be cleared',
        ]));
        $id = $created->getData()['id'];

        $put = $this->api->handle(new ApiRequest('PUT', "/plats/$id", [], [
            'storage' => '',
        ]));
        $this->assertEquals(200, $put->getStatus());
        $this->assertNull($put->getData()['storage'], 'Empty string clears storage; response reflects null');

        // Verify the row is gone (not just blank)
        $freshFactory = new EntityFactory('plat', 'platsFile', $this->system);
        $freshApi = new ApiHandler($this->system);
        $freshApi->register('plats', $freshFactory);
        $got = $freshApi->handle(new ApiRequest('GET', "/plats/$id", ['include_storage' => 'yes']));
        $this->assertNull($got->getData()['storage']);
    }

    public function testPutWithoutStorageDoesNotTouchExisting(): void
    {
        $created = $this->api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Ossobuco', 'prix' => '18.00', 'categorie' => 'Plat', 'disponibilite' => 'oui',
            'storage' => 'preserved through partial update',
        ]));
        $id = $created->getData()['id'];

        $this->api->handle(new ApiRequest('PUT', "/plats/$id", [], [
            'prix' => '19.00',
        ]));

        $freshFactory = new EntityFactory('plat', 'platsFile', $this->system);
        $freshApi = new ApiHandler($this->system);
        $freshApi->register('plats', $freshFactory);
        $got = $freshApi->handle(new ApiRequest('GET', "/plats/$id", ['include_storage' => 'true']));
        $this->assertSame('preserved through partial update', $got->getData()['storage']);
    }

    // --- Auto-embedding on REST writes ---

    public function testPostTriggersEmbedWhenServiceAvailable(): void
    {
        $spy = $this->createMock(EmbeddingService::class);
        $spy->method('isAvailable')->willReturn(true);
        $spy->expects($this->once())
            ->method('embedEntity')
            ->with($this->isInstanceOf(\SandraCore\Entity::class));

        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $api = new ApiHandler($this->system, $spy);
        $api->register('plats', $factory);

        $response = $api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Bruschetta', 'prix' => '6.00', 'categorie' => 'Entrée', 'disponibilite' => 'oui',
            'storage' => 'long description that should land in the embedding input',
        ]));
        $this->assertEquals(201, $response->getStatus());
    }

    public function testPutTriggersEmbedWhenServiceAvailable(): void
    {
        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $created = $factory->createNew([
            'nom' => 'Pana Cotta', 'prix' => '6.50', 'categorie' => 'Dessert', 'disponibilite' => 'oui',
        ]);
        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $factory->populateLocal();

        $spy = $this->createMock(EmbeddingService::class);
        $spy->method('isAvailable')->willReturn(true);
        $spy->expects($this->once())->method('embedEntity');

        $api = new ApiHandler($this->system, $spy);
        $api->register('plats', $factory);

        $response = $api->handle(new ApiRequest('PUT', "/plats/{$created->subjectConcept->idConcept}", [], [
            'storage' => 'new long body to re-embed',
        ]));
        $this->assertEquals(200, $response->getStatus());
    }

    public function testEmbedNotCalledWhenServiceUnavailable(): void
    {
        $spy = $this->createMock(EmbeddingService::class);
        $spy->method('isAvailable')->willReturn(false);
        $spy->expects($this->never())->method('embedEntity');

        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $api = new ApiHandler($this->system, $spy);
        $api->register('plats', $factory);

        $api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Fritto Misto', 'prix' => '12.00', 'categorie' => 'Entrée', 'disponibilite' => 'oui',
        ]));
    }

    public function testEmbedNotCalledWhenServiceAbsent(): void
    {
        // No EmbeddingService at all (legacy callers / no OPENAI_API_KEY).
        // Just verifying no fatal — the handler must tolerate $this->embeddingService === null.
        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $api = new ApiHandler($this->system);
        $api->register('plats', $factory);

        $response = $api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Caprese', 'prix' => '8.00', 'categorie' => 'Entrée', 'disponibilite' => 'oui',
        ]));
        $this->assertEquals(201, $response->getStatus());
    }

    public function testEmbedFailureDoesNotBlockWrite(): void
    {
        $spy = $this->createMock(EmbeddingService::class);
        $spy->method('isAvailable')->willReturn(true);
        $spy->method('embedEntity')->willThrowException(new \RuntimeException('OpenAI 429'));

        $factory = new EntityFactory('plat', 'platsFile', $this->system);
        $api = new ApiHandler($this->system, $spy);
        $api->register('plats', $factory);

        $response = $api->handle(new ApiRequest('POST', '/plats', [], [
            'nom' => 'Affogato', 'prix' => '7.00', 'categorie' => 'Dessert', 'disponibilite' => 'oui',
        ]));
        // Write must succeed even if the embed pipeline blows up.
        $this->assertEquals(201, $response->getStatus());
        $this->assertSame('Affogato', $response->getData()['refs']['nom']);
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
