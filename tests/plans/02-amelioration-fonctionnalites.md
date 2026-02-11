# Plan d'Amelioration des Fonctionnalites - Sandra Graph Database

## Vue d'ensemble

Ce document propose de nouvelles fonctionnalites pour Sandra, basees sur l'analyse des use cases existants
dans les tests unitaires et les besoins classiques d'un graph database.

---

## 1. SYSTEME DE REQUETES AVANCE (Query Builder Fluent)

### Etat actuel
Les filtres sont configures via `setFilter($verb, $target)` avant `populateLocal()`. C'est fonctionnel mais :
- Pas de chainage possible
- Pas de conditions OR/AND complexes
- Pas de sous-requetes

### Proposition
Creer un Query Builder fluent inspire de Eloquent/Doctrine :

```php
$results = $factory->query()
    ->where('name', 'like', 'Mars%')
    ->whereHasBrother('orbits', $sun)
    ->whereRef('mass[earth]', '>', 0.5)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->offset(0)
    ->get();

// Requetes complexes
$results = $factory->query()
    ->where(function($q) {
        $q->whereRef('type', '=', 'rocky')
          ->orWhereRef('type', '=', 'gas_giant');
    })
    ->whereHasBrother('inSystem', $solarSystem)
    ->whereNotDeleted()
    ->count();
```

### Fichiers a creer
- `src/SandraCore/Query/QueryBuilder.php` - Construction des requetes
- `src/SandraCore/Query/WhereClause.php` - Conditions individuelles
- `src/SandraCore/Query/QueryResult.php` - Resultats pagines avec meta

### Impact
- API beaucoup plus intuitive
- Support des requetes complexes sans SQL direct
- Base pour un futur langage de requete (type GraphQL)

---

## 2. SYSTEME D'EVENEMENTS (Event System)

### Etat actuel
Aucun systeme d'evenements. Les operations se font de maniere synchrone sans possibilite d'intercept.

### Proposition
Permettre de reagir aux operations CRUD sur les entites :

```php
// Enregistrer des listeners
$factory->on('entity.created', function(Entity $entity) {
    Logger::info("New planet created: " . $entity->get('name'));
});

$factory->on('entity.updated', function(Entity $entity, array $changes) {
    AuditLog::record($entity, $changes);
});

$factory->on('brother.linked', function(Entity $source, string $verb, Entity $target) {
    // Propager la relation dans un cache externe
});

$factory->on('entity.deleted', function(Entity $entity) {
    // Nettoyage cascade
});
```

### Fichiers a creer
- `src/SandraCore/Events/EventDispatcher.php`
- `src/SandraCore/Events/EntityEvent.php`
- `src/SandraCore/Events/TripletEvent.php`

### Use cases
- Audit trail / logging automatique
- Invalidation de cache
- Synchronisation avec des systemes externes
- Validation avant ecriture (pre-events)
- Cascade de suppressions

---

## 3. SYSTEME DE VALIDATION

### Etat actuel
Aucune validation des donnees avant insertion. Les references sont tronquees silencieusement a 255 caracteres.

### Proposition

```php
$factory->setValidation([
    'name' => ['required', 'string', 'max:100'],
    'mass[earth]' => ['numeric', 'min:0'],
    'email' => ['email'],
    'assetId' => ['required', 'unique'], // unique dans la factory
]);

// A la creation
try {
    $entity = $factory->createNew(['name' => '', 'mass[earth]' => -5]);
} catch (ValidationException $e) {
    // $e->getErrors() = ['name' => ['required'], 'mass[earth]' => ['min:0']]
}

// Validation personnalisee
$factory->addValidator('coordinates', function($value) {
    return preg_match('/^-?\d+\.\d+,-?\d+\.\d+$/', $value);
});
```

### Fichiers a creer
- `src/SandraCore/Validation/Validator.php`
- `src/SandraCore/Validation/ValidationRule.php`
- `src/SandraCore/Validation/ValidationException.php`

---

## 4. TRAVERSEE DE GRAPHE (Graph Traversal)

### Etat actuel
On peut acceder aux brothers directs et aux factories jointes, mais pas traverser le graphe en profondeur.

### Proposition
Permettre la navigation recursive dans le graphe :

```php
// Trouver tous les chemins entre deux entites
$paths = $graph->findPaths($entityA, $entityZ, maxDepth: 5);

// Breadth-first search depuis une entite
$related = $graph->bfs($startEntity, 'knows', maxDepth: 3);
// Retourne: [depth1 => [entities], depth2 => [entities], ...]

// Trouver les ancetres (remonter les relations)
$ancestors = $graph->ancestors($entity, 'isChildOf');
// Retourne l'arbre complet: entity -> parent -> grandparent -> ...

// Trouver les descendants
$descendants = $graph->descendants($rootEntity, 'isParentOf', maxDepth: 10);

// Detection de cycles
$hasCycle = $graph->hasCycle($entity, 'dependsOn');

// Plus court chemin
$shortest = $graph->shortestPath($from, $to, ['knows', 'workedWith']);
```

### Fichiers a creer
- `src/SandraCore/Graph/GraphTraverser.php`
- `src/SandraCore/Graph/Path.php`
- `src/SandraCore/Graph/TraversalResult.php`

### Impact
- Permet de modeliser des hierarchies (categorie, taxonomies)
- Analyse de reseaux sociaux
- Dependances de paquets/modules
- Arbres genealogiques, structures organisationnelles

---

## 5. SYSTEME DE CACHE INTEGRE

### Etat actuel
Les entites sont chargees en memoire mais sans strategie de cache. Chaque `populateLocal()` retourne en base.

### Proposition

```php
// Cache en memoire avec TTL
$system->setCache(new MemoryCache(ttl: 300)); // 5 minutes

// Cache Redis (pour multi-process)
$system->setCache(new RedisCache($redis, prefix: 'sandra:', ttl: 600));

// Cache fichier (pour CLI/batch)
$system->setCache(new FileCache('/tmp/sandra-cache/'));

// Interface simple
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): void;
    public function delete(string $key): void;
    public function flush(): void;
    public function has(string $key): bool;
}

// Utilisation automatique dans EntityFactory
$factory->enableCache(true); // Active le cache sur cette factory
$factory->populateLocal();   // 1er appel: charge depuis DB, met en cache
$factory->populateLocal();   // 2e appel: depuis le cache

// Invalidation intelligente
$factory->createNew([...]); // Invalide automatiquement le cache de cette factory
```

### Fichiers a creer
- `src/SandraCore/Cache/CacheInterface.php`
- `src/SandraCore/Cache/MemoryCache.php`
- `src/SandraCore/Cache/RedisCache.php`
- `src/SandraCore/Cache/FileCache.php`
- `src/SandraCore/Cache/NullCache.php`

---

## 6. EXPORT/IMPORT MULTI-FORMAT

### Etat actuel
Sandra supporte le JSON via le `Gossiper` et un export basique. Mais il manque :
- Export CSV/TSV
- Export RDF/Turtle (standard du web semantique)
- Export GraphML/GEXF (pour visualisation avec Gephi)
- Import bulk performant

### Proposition

```php
// Export
$exporter = new CsvExporter($factory);
$exporter->setColumns(['name', 'mass[earth]', 'radius[km]']);
$exporter->export('/path/to/planets.csv');

$rdfExporter = new RdfExporter($factory);
$rdfExporter->setNamespace('http://example.org/space#');
$rdfExporter->export('/path/to/planets.ttl');

$graphmlExporter = new GraphMLExporter([$starFactory, $planetFactory]);
$graphmlExporter->includeRelations(['orbits', 'illuminates']);
$graphmlExporter->export('/path/to/solarsystem.graphml');

// Import bulk (beaucoup plus rapide que createNew en boucle)
$importer = new BulkImporter($factory, $system);
$importer->fromCsv('/path/to/data.csv', [
    'column_map' => ['Name' => 'name', 'Mass' => 'mass[earth]'],
    'batch_size' => 1000,
    'on_duplicate' => 'update', // ou 'skip', 'error'
]);
echo $importer->getStats(); // "Imported 5000 entities in 2.3s"
```

### Fichiers a creer
- `src/SandraCore/Export/ExporterInterface.php`
- `src/SandraCore/Export/CsvExporter.php`
- `src/SandraCore/Export/RdfExporter.php`
- `src/SandraCore/Export/GraphMLExporter.php`
- `src/SandraCore/Import/BulkImporter.php`
- `src/SandraCore/Import/CsvImporter.php`

---

## 7. MIGRATION ET VERSIONING DU SCHEMA

### Etat actuel
`Setup::flushDatagraph()` detruit et recree les tables. Pas de systeme de migration.

### Proposition

```php
// Definir une migration
class AddLocationToEntities extends Migration
{
    public function up(System $system): void
    {
        // Ajouter un concept systeme
        $system->systemConcept->getOrCreate('location');
        $system->systemConcept->getOrCreate('latitude');
        $system->systemConcept->getOrCreate('longitude');
    }

    public function down(System $system): void
    {
        // Rollback si necessaire
    }
}

// Executer les migrations
$migrator = new Migrator($system);
$migrator->run();           // Execute les migrations pendantes
$migrator->rollback();      // Annule la derniere migration
$migrator->status();        // Liste les migrations executees/pendantes
```

### Fichiers a creer
- `src/SandraCore/Migration/Migration.php`
- `src/SandraCore/Migration/Migrator.php`
- `src/SandraCore/Migration/MigrationRepository.php`

---

## 8. API REST/GraphQL AUTO-GENEREE

### Etat actuel
Sandra est une librairie PHP. Pour l'exposer via API, il faut ecrire le code manuellement.

### Proposition
Generer automatiquement une API a partir des factories :

```php
// Configuration
$api = new SandraApi($system);

$api->expose($planetFactory, [
    'read' => true,
    'create' => true,
    'update' => true,
    'delete' => false,
    'searchable' => ['name', 'type'],
    'relations' => ['orbits', 'inConstellation'],
]);

// Genere automatiquement :
// GET    /api/planets              - Liste (pagine)
// GET    /api/planets/:id          - Detail
// GET    /api/planets?name=Mars    - Recherche
// POST   /api/planets              - Creation
// PUT    /api/planets/:id          - Mise a jour
// GET    /api/planets/:id/orbits   - Relations

// GraphQL (optionnel)
$graphql = new SandraGraphQL($system);
$graphql->registerFactory('Planet', $planetFactory);
$graphql->registerFactory('Star', $starFactory);
$graphql->registerRelation('Star', 'illuminates', 'Planet');
// Schema GraphQL genere automatiquement
```

### Fichiers a creer
- `src/SandraCore/Api/RestApiGenerator.php`
- `src/SandraCore/Api/GraphQLSchemaBuilder.php`
- `src/SandraCore/Api/ApiMiddleware.php`

---

## 9. INDEXATION ET RECHERCHE FULL-TEXT

### Etat actuel
La recherche passe par `DatabaseAdapter::searchConcept()` avec des `LIKE` sur les references. Pas d'indexation full-text.

### Proposition

```php
// Indexation
$indexer = new FullTextIndexer($system);
$indexer->indexFactory($planetFactory, ['name', 'description']);

// Recherche
$results = $indexer->search('rocky planet near sun', [
    'factory' => $planetFactory,
    'fuzzy' => true,
    'limit' => 20,
]);

// Ou avec un moteur externe (Elasticsearch/Meilisearch)
$system->setSearchEngine(new MeilisearchAdapter($meiliClient));

$planetFactory->createNew(['name' => 'Mars']); // Auto-indexe

$results = $factory->search('mars rocky', limit: 10);
```

### Fichiers a creer
- `src/SandraCore/Search/SearchInterface.php`
- `src/SandraCore/Search/MySQLFullText.php`
- `src/SandraCore/Search/MeilisearchAdapter.php`

---

## 10. SUPPORT MULTI-DATABASE

### Etat actuel
Sandra est couple a MySQL. `PdoConnexionWrapper` hard-code le driver MySQL.

### Proposition
Supporter d'autres bases via PDO :

```php
// SQLite (ideal pour tests et applications embarquees)
$system = new System(env: 'test', driver: 'sqlite', path: ':memory:');

// PostgreSQL (JSONB pour les references, meilleure performance analytique)
$system = new System(env: 'prod', driver: 'pgsql', host: 'localhost', db: 'sandra');

// Interface abstraite
interface DatabaseDriver
{
    public function createTables(string $prefix): void;
    public function getInsertTripletSQL(): string;
    public function getSearchSQL(): string;
    public function supportsFullText(): bool;
}
```

### Fichiers a creer
- `src/SandraCore/Driver/DriverInterface.php`
- `src/SandraCore/Driver/MySQLDriver.php`
- `src/SandraCore/Driver/SQLiteDriver.php`
- `src/SandraCore/Driver/PostgreSQLDriver.php`

### Impact
- Tests unitaires rapides avec SQLite en memoire (plus besoin de MySQL pour les tests)
- Deployement simplifie pour petits projets
- PostgreSQL pour les gros volumes

---

## 11. OBSERVABILITE ET METRIQUES

### Etat actuel
Le `Logger` est un no-op. Le `DebugStack` existe mais n'est pas exploite.

### Proposition

```php
// Metriques automatiques
$system->enableMetrics();

$factory->populateLocal();
// Metrics: sandra.query.duration=45ms, sandra.query.rows=1500, sandra.memory.peak=12MB

$factory->createNew([...]);
// Metrics: sandra.entity.created, sandra.triplet.created=1, sandra.reference.created=3

// Dashboard
$stats = $system->getMetrics();
// [
//   'queries_total' => 156,
//   'queries_avg_ms' => 12.3,
//   'entities_in_memory' => 5000,
//   'cache_hit_rate' => 0.85,
//   'slow_queries' => [...],
// ]

// Export Prometheus/StatsD
$system->setMetricsExporter(new PrometheusExporter());
```

### Fichiers a creer
- `src/SandraCore/Metrics/MetricsCollector.php`
- `src/SandraCore/Metrics/QueryProfiler.php`

---

## 12. SNAPSHOT ET TIME-TRAVEL

### Etat actuel
Les entites ont un `creationTimestamp` mais pas d'historique des modifications.

### Proposition
Permettre de voir l'etat du graphe a un instant T :

```php
// Activer le versioning sur une factory
$factory->enableVersioning();

$entity = $factory->createNew(['name' => 'Mars', 'status' => 'unknown']);
$entity->update(['status' => 'explored']);
$entity->update(['status' => 'colonized']);

// Voir l'historique
$history = $entity->getHistory();
// [
//   ['timestamp' => '2024-01-01', 'status' => 'unknown'],
//   ['timestamp' => '2024-06-01', 'status' => 'explored'],
//   ['timestamp' => '2025-01-01', 'status' => 'colonized'],
// ]

// Time travel
$entityAt = $entity->at('2024-06-01');
$entityAt->get('status'); // 'explored'

// Snapshot complet d'une factory
$snapshot = $factory->snapshotAt('2024-01-01');
```

---

## Resume et Roadmap suggeree

### Phase 1 - Fondations (pre-requis : ameliorations code) - FAIT
1. Query Builder Fluent - FAIT
2. Systeme de Validation - FAIT
3. Systeme de Cache - FAIT

### Phase 2 - Fonctionnalites avancees - FAIT
4. Traversee de Graphe - FAIT
5. Systeme d'Evenements - FAIT
6. Export/Import CSV - FAIT

### Phase 3 - Ecosystem - FAIT
7. API REST auto-generee - FAIT
8. Recherche full-text - FAIT
9. Support multi-database - FAIT

### Phase 4 - Enterprise
10. Migrations
11. Observabilite
12. Snapshot / Time-travel
