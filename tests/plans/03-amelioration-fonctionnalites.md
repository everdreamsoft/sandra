# Plan d'Amelioration des Fonctionnalites - Phase 3 - Sandra Graph Database

## Vue d'ensemble

Ce document propose de nouvelles fonctionnalites pour Sandra, basees sur l'analyse de l'etat actuel du codebase
apres les phases 1 et 2. Il identifie les gaps par rapport aux graph databases modernes (Neo4j, ArangoDB)
et les besoins concrets pour une utilisation enterprise.

**Etat actuel** : QueryBuilder, Events, Validation, Cache, GraphTraversal, CSV Export/Import, REST API,
BasicSearch, Multi-Database (MySQL/SQLite), Brother/Joined entity support dans l'API.

---

## 1. OPERATIONS BATCH (Insertion/Mise a jour en masse)

### Etat actuel
Creer 1000 entites = 1000 appels individuels a `createNew()`, chacun avec sa propre transaction.
C'est 10-50x plus lent qu'une insertion batch.

### Proposition

```php
// Import en masse — single transaction, multi-row INSERT
$batch = $factory->batch();
foreach ($csvRows as $row) {
    $batch->add(['name' => $row['name'], 'mass' => $row['mass']]);
}
$result = $batch->execute(batchSize: 1000);
// BatchResult: created=5000, skipped=3, errors=[], duration=2.3s

// Mise a jour en masse
$factory->query()
    ->where('status', 'pending')
    ->update(['status' => 'processed']);

// Suppression en masse
$factory->query()
    ->where('created_at', '<', '2024-01-01')
    ->delete();
```

### Fichiers a creer
- `src/SandraCore/Batch/BatchInsert.php` — accumule les entites, execute en une transaction
- `src/SandraCore/Batch/BatchResult.php` — resultat avec statistiques

### Fichiers a modifier
- `src/SandraCore/EntityFactory.php` — methode `batch(): BatchInsert`
- `src/SandraCore/Query/QueryBuilder.php` — methodes `update()` et `delete()`
- `src/SandraCore/DatabaseAdapter.php` — methode `bulkInsertReferences()` multi-row

### Impact
- 10-50x plus rapide pour les imports massifs
- CsvImporter peut utiliser BatchInsert au lieu de boucler sur createNew()
- Base pour la synchronisation avec des systemes externes

---

## 2. REQUETES AVANCEES (OR, sous-requetes, aggregation)

### Etat actuel
Le QueryBuilder supporte AND uniquement (whereRef + whereHasBrother). Pas de OR, pas de sous-requetes,
pas d'aggregation.

### Proposition

```php
// OR conditions
$factory->query()
    ->where('type', 'rocky')
    ->orWhere('type', 'gas_giant')
    ->get();

// Groupement de conditions
$factory->query()
    ->where(function($q) {
        $q->where('mass', '>', 0.5)
          ->orWhere('radius', '>', 5000);
    })
    ->whereHasBrother('inSystem', $solarSystem)
    ->get();

// Aggregation
$factory->query()
    ->whereRef('type', '=', 'rocky')
    ->avg('mass');          // float

$factory->query()->sum('price');    // float
$factory->query()->min('distance'); // mixed
$factory->query()->max('distance'); // mixed

// Group by (retourne des stats)
$stats = $factory->query()
    ->groupBy('category')
    ->aggregate(['count', 'avg:price', 'sum:quantity']);
// [
//   'pizza' => ['count' => 12, 'avg_price' => '14.50', 'sum_quantity' => '340'],
//   'pasta' => ['count' => 8, 'avg_price' => '12.00', 'sum_quantity' => '180'],
// ]

// WHERE IN
$factory->query()
    ->whereIn('name', ['Mars', 'Venus', 'Earth'])
    ->get();

// LIKE / pattern matching
$factory->query()
    ->where('name', 'LIKE', 'Mar%')
    ->get();
```

### Fichiers a modifier
- `src/SandraCore/Query/QueryBuilder.php` — ajouter `orWhere()`, `whereIn()`, `avg()`, `sum()`, `min()`, `max()`, `groupBy()`
- `src/SandraCore/Query/WhereClause.php` — ajouter le support OR (type `OR`)
- `src/SandraCore/DatabaseAdapter.php` — ajouter `searchConceptByRefOr()` pour les requetes OR au niveau SQL

### Fichiers a creer
- `src/SandraCore/Query/WhereGroup.php` — groupe de conditions (AND/OR)
- `src/SandraCore/Query/AggregateResult.php` — resultat d'aggregation

### Impact
- API aussi expressive que Eloquent pour les requetes courantes
- Moins besoin de SQL custom dans les projets utilisateurs
- Base pour une future interface GraphQL

---

## 3. CONTROLE DE CONCURRENCE OPTIMISTE

### Etat actuel
Aucun mecanisme de protection contre les modifications concurrentes. Race condition dans
`rawCreateTriplet()` (check-then-insert sans verrouillage). Dans un contexte multi-process
(web server avec workers), deux requetes peuvent creer des triplets dupliques.

### Proposition

```php
// Version tracking automatique
$entity = $factory->query()->where('name', 'Mars')->first();
$entity->update(['status' => 'explored']);
// Si un autre process a modifie Mars entre le read et l'update:
// -> ConcurrencyException thrown

// Gestion manuelle
try {
    $entity->update(['status' => 'colonized']);
} catch (ConcurrencyException $e) {
    $fresh = $factory->query()->where('name', 'Mars')->first();
    // Re-appliquer la logique metier avec les donnees fraiches
    $fresh->update(['status' => 'colonized']);
}

// Batch avec retry automatique
$factory->withRetry(3)->query()
    ->where('status', 'pending')
    ->update(['status' => 'processing']);
```

### Fichiers a creer
- `src/SandraCore/Concurrency/VersionTracker.php` — gestion des versions d'entites
- `src/SandraCore/Exception/ConcurrencyException.php`

### Fichiers a modifier
- `src/SandraCore/Entity.php` — ajouter un champ `version` et verifier avant update
- `src/SandraCore/DatabaseAdapter.php` — ajouter `WHERE version = :expected` aux updates
- `src/SandraCore/EntityFactory.php` — option `withRetry()` pour les operations batch

### Impact
- Integrite des donnees en environnement multi-process
- Prerequis pour toute utilisation en production serieuse
- Compatible avec les architectures microservices

---

## 4. SYSTEME DE MIDDLEWARE / PLUGINS

### Etat actuel
Le systeme d'evenements (EventDispatcher) permet des listeners, mais il n'y a pas de pipeline
middleware pour transformer les donnees avant/apres les operations CRUD.

### Proposition

```php
interface SandraMiddleware {
    public function beforeCreate(array &$data, EntityFactory $factory): void;
    public function afterCreate(Entity $entity, EntityFactory $factory): void;
    public function beforeUpdate(Entity $entity, array &$changes): void;
    public function afterUpdate(Entity $entity, array $changes): void;
    public function beforeDelete(Entity $entity): void;
    public function transformQuery(QueryBuilder $query): void;
}

// Exemples de middleware

// Audit trail automatique
class AuditMiddleware implements SandraMiddleware {
    public function afterCreate(Entity $entity, EntityFactory $factory): void {
        $this->auditFactory->createNew([
            'action' => 'create',
            'entity_id' => $entity->subjectConcept->idConcept,
            'timestamp' => time(),
            'user' => $this->currentUser,
        ]);
    }
}

// Slug automatique
class SlugMiddleware implements SandraMiddleware {
    public function beforeCreate(array &$data, EntityFactory $factory): void {
        if (isset($data['name']) && !isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }
    }
}

// Rate limiting
class RateLimitMiddleware implements SandraMiddleware {
    public function beforeCreate(array &$data, EntityFactory $factory): void {
        if ($this->isRateLimited($factory)) {
            throw new RateLimitException('Too many creates per minute');
        }
    }
}

// Utilisation
$factory->use(new AuditMiddleware($auditFactory, $currentUser));
$factory->use(new SlugMiddleware());
```

### Fichiers a creer
- `src/SandraCore/Middleware/SandraMiddleware.php` — interface
- `src/SandraCore/Middleware/MiddlewareStack.php` — pile chainable
- `src/SandraCore/Middleware/AuditMiddleware.php` — exemple audit trail

### Fichiers a modifier
- `src/SandraCore/FactoryBase.php` — propriete `MiddlewareStack`, methode `use()`
- `src/SandraCore/EntityFactory.php` — executer la pile dans `createNew()`, `update()`, `delete()`

### Impact
- Extensibilite sans modifier le code source de Sandra
- Separation des preoccupations (validation, audit, cache, permissions)
- Ecosysteme de plugins tiers possible

---

## 5. MIGRATION ET VERSIONING DU SCHEMA

### Etat actuel
`Setup::flushDatagraph()` detruit et recree les tables. Pas de systeme de migration.
En production, les changements de schema necessitent du SQL manuel.

### Proposition

```php
// Definir une migration
class Migration_001_AddGeoLocation extends Migration {
    public function up(System $system): void {
        // Ajouter des concepts systeme
        $system->systemConcept->getOrCreate('latitude');
        $system->systemConcept->getOrCreate('longitude');
        $system->systemConcept->getOrCreate('geo_accuracy');
    }

    public function down(System $system): void {
        // Rollback : pas de suppression de concept (trop dangereux)
        // Documenter seulement
    }
}

// Executer les migrations
$migrator = new Migrator($system, __DIR__ . '/migrations/');
$migrator->status();       // Liste: [001: pending, 002: applied]
$migrator->migrate();      // Execute les pendantes
$migrator->rollback(1);   // Annule la derniere

// Les migrations sont trackes dans une table `_migrations`
// Chaque migration a: id, name, applied_at, checksum
```

### Fichiers a creer
- `src/SandraCore/Migration/Migration.php` — classe abstraite
- `src/SandraCore/Migration/Migrator.php` — execution + tracking
- `src/SandraCore/Migration/MigrationRepository.php` — stockage dans la table `_migrations`
- `src/SandraCore/Migration/MigrationGenerator.php` — genere un squelette de migration

### Impact
- Deployements en production sans perte de donnees
- Historique des changements de schema
- Collaboration multi-developpeurs facilitee

---

## 6. OBSERVABILITE ET METRIQUES

### Etat actuel
Le Logger existe (NullLogger, FileLogger, ConsoleLogger) mais il n'y a pas de metriques
structurees, pas de profiling de requetes, pas de health checks.

### Proposition

```php
// Profiling automatique des requetes
$system->enableProfiling();

$factory->populateLocal();
// Log: [QUERY] 45ms | SELECT ... | rows=1500 | memory=12MB

// Metriques aggregees
$metrics = $system->getMetrics();
// [
//   'queries_total' => 156,
//   'queries_avg_ms' => 12.3,
//   'queries_slow_count' => 3,   // > 100ms
//   'entities_in_memory' => 5000,
//   'cache_hits' => 45,
//   'cache_misses' => 12,
//   'cache_hit_rate' => 0.79,
//   'transactions_total' => 23,
//   'errors_total' => 1,
// ]

// Health check
$health = $system->healthCheck();
// [
//   'database' => 'ok',
//   'tables' => ['concepts' => 'ok', 'links' => 'ok', 'references' => 'ok'],
//   'connection_pool' => ['active' => 3, 'idle' => 7, 'max' => 10],
//   'memory' => ['current' => '45MB', 'peak' => '120MB', 'limit' => '256MB'],
// ]

// Slow query detection
$profiler = $system->getProfiler();
$slowQueries = $profiler->getSlowQueries(thresholdMs: 100);
// [
//   ['sql' => 'SELECT ...', 'duration_ms' => 234, 'trace' => '...'],
// ]

// Export Prometheus
echo $system->getMetrics()->toPrometheus();
// sandra_queries_total 156
// sandra_query_duration_avg_ms 12.3
// sandra_entities_in_memory 5000
```

### Fichiers a creer
- `src/SandraCore/Metrics/MetricsCollector.php` — collecte les metriques
- `src/SandraCore/Metrics/QueryProfiler.php` — profiling par requete avec trace
- `src/SandraCore/Metrics/HealthChecker.php` — verification de sante
- `src/SandraCore/Metrics/PrometheusExporter.php` — export format Prometheus

### Fichiers a modifier
- `src/SandraCore/System.php` — `enableProfiling()`, `getMetrics()`, `healthCheck()`
- `src/SandraCore/QueryExecutor.php` — enregistrer la duree de chaque requete
- `src/SandraCore/DatabaseAdapter.php` — propagation des metriques

### Impact
- Detecter les goulots d'etranglement en production
- Alerting automatique sur les requetes lentes
- Dashboard operationnel
- Prerequis pour le scaling

---

## 7. EXPORT MULTI-FORMAT (GraphML, RDF, JSON-LD)

### Etat actuel
Export CSV existe. Import CSV existe. Gossiper supporte JSON. Mais il manque les formats
standards du monde des graphes et du web semantique.

### Proposition

```php
// GraphML — pour visualisation avec Gephi/yEd
$exporter = new GraphMLExporter();
$xml = $exporter->export($planetFactory, [
    'includeRelations' => ['orbits', 'illuminates'],
    'nodeAttributes' => ['name', 'mass', 'type'],
]);
file_put_contents('solar_system.graphml', $xml);

// RDF/Turtle — standard web semantique
$exporter = new RdfExporter();
$exporter->setNamespace('http://example.org/space#');
$turtle = $exporter->export($planetFactory, [
    'predicateMap' => [
        'name' => 'rdfs:label',
        'mass' => 'space:mass',
    ],
]);

// JSON-LD — linked data
$exporter = new JsonLdExporter();
$jsonld = $exporter->export($planetFactory, [
    '@context' => 'http://schema.org/',
    'typeMapping' => 'Planet',
]);

// SQL Dump — pour backup/migration
$exporter = new SqlDumpExporter();
$sql = $exporter->exportFactory($planetFactory);
// INSERT INTO concepts ...
// INSERT INTO links ...
// INSERT INTO references ...
```

### Fichiers a creer
- `src/SandraCore/Export/GraphMLExporter.php`
- `src/SandraCore/Export/RdfExporter.php`
- `src/SandraCore/Export/JsonLdExporter.php`
- `src/SandraCore/Export/SqlDumpExporter.php`

### Impact
- Interoperabilite avec l'ecosysteme graph database (Neo4j, Gephi)
- Conformite web semantique (W3C)
- Backup/restore fiable
- Visualisation de donnees

---

## 8. SNAPSHOT ET HISTORIQUE (Time-Travel)

### Etat actuel
Les entites ont un `creationTimestamp` mais pas d'historique des modifications.
Impossible de savoir quel etait l'etat d'une entite hier.

### Proposition

```php
// Activer le versioning sur une factory
$factory->enableVersioning();

$entity = $factory->createNew(['name' => 'Mars', 'status' => 'unknown']);
$entity->update(['status' => 'explored']);
$entity->update(['status' => 'colonized']);

// Historique
$history = $entity->getHistory();
// [
//   ['version' => 1, 'timestamp' => 1706745600, 'changes' => ['name' => 'Mars', 'status' => 'unknown']],
//   ['version' => 2, 'timestamp' => 1706832000, 'changes' => ['status' => 'explored']],
//   ['version' => 3, 'timestamp' => 1706918400, 'changes' => ['status' => 'colonized']],
// ]

// Time travel
$marsYesterday = $entity->at('2024-06-01');
echo $marsYesterday->get('status'); // 'explored'

// Diff entre versions
$diff = $entity->diff(version: 1, with: 3);
// ['status' => ['from' => 'unknown', 'to' => 'colonized']]

// Snapshot complet d'une factory
$snapshot = $factory->snapshotAt('2024-01-01');
foreach ($snapshot as $entity) {
    echo $entity->get('name') . ': ' . $entity->get('status');
}
```

### Fichiers a creer
- `src/SandraCore/Versioning/VersionManager.php` — gestion des versions
- `src/SandraCore/Versioning/EntitySnapshot.php` — snapshot a un instant T
- `src/SandraCore/Versioning/ChangeRecord.php` — enregistrement d'un changement

### Design
- Chaque modification cree une entree dans une table `_entity_history`
- Structure: `entity_id, version, field, old_value, new_value, timestamp, user`
- Le versioning est opt-in per factory pour ne pas impacter les performances
- `at()` reconstruit l'etat en rejouant les changes depuis la version 1

### Impact
- Audit trail complet
- Compliance reglementaire (GDPR, SOX)
- Debug en production (qu'est-ce qui a change ?)
- Undo/redo possible

---

## 9. API REST AVANCEE (PATCH, Bulk, Auth, Pagination curseur)

### Etat actuel
L'API REST supporte GET/POST/PUT/DELETE avec brothers et joined entities.
Mais il manque des fonctionnalites clés pour une API production-ready.

### Proposition

```php
// PATCH — mise a jour partielle (RFC 7396)
// PUT remplace toutes les refs, PATCH ne change que celles fournies
$api->handle(new ApiRequest('PATCH', '/plats/42', body: ['prix' => '15.00']));

// Bulk operations
$api->handle(new ApiRequest('POST', '/plats/bulk', body: [
    ['name' => 'Pizza', 'prix' => '12'],
    ['name' => 'Pasta', 'prix' => '10'],
    ['name' => 'Risotto', 'prix' => '14'],
]));
// Response: 201, {created: 3, errors: []}

// Pagination curseur (plus performant que offset pour de grands datasets)
$api->handle(new ApiRequest('GET', '/plats', query: [
    'cursor' => 'eyJpZCI6NDIsImRpciI6Im5leHQifQ==',
    'limit' => 20,
]));
// Response: {data: [...], cursor: {next: '...', prev: '...'}}

// Filtrage avance dans l'URL
$api->handle(new ApiRequest('GET', '/plats', query: [
    'filter[prix][gt]' => '10',
    'filter[categorie]' => 'pizza',
    'sort' => '-prix',  // desc
    'fields' => 'name,prix',  // sparse fieldsets
]));

// Authentification middleware
$api->addMiddleware(new ApiKeyAuth($validKeys));
$api->addMiddleware(new JwtAuth($jwtSecret));
$api->addMiddleware(new RateLimiter(100, 'per_minute'));
```

### Fichiers a modifier
- `src/SandraCore/Api/ApiHandler.php` — PATCH, bulk, cursor pagination, filtrage URL

### Fichiers a creer
- `src/SandraCore/Api/CursorPaginator.php` — pagination par curseur base64
- `src/SandraCore/Api/ApiMiddlewareInterface.php` — interface middleware API
- `src/SandraCore/Api/Auth/ApiKeyAuth.php` — authentification par cle API
- `src/SandraCore/Api/Auth/JwtAuth.php` — authentification JWT
- `src/SandraCore/Api/RateLimiter.php` — limitation de requetes

### Impact
- API production-ready
- Meilleure performance pour les grands datasets (curseur vs offset)
- Securite (authentification, rate limiting)
- Conformite REST standards (JSON:API, HAL)

---

## 10. ALGORITHMES DE GRAPHE AVANCES

### Etat actuel
GraphTraverser supporte BFS, DFS, ancestors, descendants, hasCycle, findPaths, shortestPath.
Mais il manque les algorithmes classiques d'analyse de graphes.

### Proposition

```php
$analyzer = new GraphAnalyzer($system);

// PageRank — importance relative des noeuds
$ranks = $analyzer->pageRank($factory, 'knows', iterations: 20);
// ['entity_42' => 0.15, 'entity_17' => 0.12, ...]

// Centralite — noeuds les plus connectes
$centrality = $analyzer->degreeCentrality($factory, 'follows');
// ['entity_42' => ['in' => 15, 'out' => 8, 'total' => 23], ...]

// Betweenness centrality — noeuds "pont"
$betweenness = $analyzer->betweennessCentrality($factory, 'knows');

// Detection de communautes (Louvain)
$communities = $analyzer->detectCommunities($factory, 'knows');
// [
//   0 => [entity_1, entity_5, entity_12],  // communaute 1
//   1 => [entity_3, entity_7, entity_9],   // communaute 2
// ]

// Composantes connexes
$components = $analyzer->connectedComponents($factory, 'knows');

// Chemin pondere
$path = $analyzer->dijkstra($from, $to, 'route', weightRef: 'distance');
// Path avec cout total
```

### Fichiers a creer
- `src/SandraCore/Graph/GraphAnalyzer.php` — algorithmes d'analyse
- `src/SandraCore/Graph/PageRank.php` — implementation PageRank iterative
- `src/SandraCore/Graph/CommunityDetection.php` — Louvain ou Label Propagation

### Impact
- Analyse de reseaux sociaux
- Recommandations basees sur le graphe
- Detection de fraude (communautes anormales)
- Optimisation de routes/logistics

---

## 11. RECHERCHE FULL-TEXT AVANCEE

### Etat actuel
`BasicSearch` fait de la recherche en memoire avec `mb_stripos`. Fonctionnel pour < 10K entites,
mais pas scalable et pas de fonctionnalites avancees.

### Proposition

```php
// Recherche MySQL FULLTEXT (sans dependance externe)
$factory->enableFullTextSearch(['name', 'description']);
// Cree un index FULLTEXT sur les colonnes de la table reference

$results = $factory->search('planet rocky atmosphere', [
    'mode' => 'boolean',  // +planet +rocky -gas
    'fuzzy' => true,      // planete ~= planet
    'highlight' => true,  // <mark>planet</mark> rocky
    'limit' => 20,
]);

// Interface pour moteurs externes
interface SearchEngineInterface {
    public function index(Entity $entity, array $fields): void;
    public function search(string $query, array $options): SearchResult;
    public function delete(Entity $entity): void;
    public function reindex(EntityFactory $factory): void;
}

// Adapter Meilisearch
$search = new MeilisearchAdapter($meiliClient, 'planets_index');
$factory->setSearchEngine($search);

// Auto-indexation via events
$factory->on(EntityEvent::ENTITY_CREATED, fn($e) => $search->index($e->entity, ['name', 'description']));
```

### Fichiers a creer
- `src/SandraCore/Search/SearchEngineInterface.php` — interface abstraite
- `src/SandraCore/Search/MySQLFullTextSearch.php` — recherche FULLTEXT native MySQL
- `src/SandraCore/Search/MeilisearchAdapter.php` — adapter Meilisearch
- `src/SandraCore/Search/SearchResult.php` — resultat avec score et highlights

### Fichiers a modifier
- `src/SandraCore/Search/BasicSearch.php` — implementer `SearchEngineInterface`
- `src/SandraCore/EntityFactory.php` — methode `setSearchEngine()`, `enableFullTextSearch()`

### Impact
- Recherche performante sur de grands volumes (100K+ entites)
- Recherche floue, phonetique, avec fautes de frappe
- Integration avec des moteurs de recherche populaires

---

## 12. DRIVER POSTGRESQL

### Etat actuel
`DatabaseDriverInterface` existe avec MySQL et SQLite. PostgreSQL est mentionne mais pas implemente.

### Proposition

```php
// Usage
$driver = new PostgreSQLDriver();
$system = new System('myApp_', true, driver: $driver);

// Fonctionnalites specifiques PostgreSQL
class PostgreSQLDriver implements DatabaseDriverInterface {
    public function getDsn(): string {
        return "pgsql:host={$this->host};dbname={$this->database}";
    }

    public function getCreateTableSQL(string $prefix): array {
        // SERIAL au lieu de AUTO_INCREMENT
        // TEXT au lieu de VARCHAR(255) pour les references
        // JSONB pour le stockage de donnees structurees
    }

    public function getUpsertReferenceSQL(string $table): string {
        return "INSERT INTO {$table} ... ON CONFLICT (linkReferenced, idConcept)
                DO UPDATE SET value = EXCLUDED.value";
    }

    // PostgreSQL supporte nativement JSONB, arrays, full-text search
    public function supportsFullText(): bool { return true; }
    public function supportsJsonb(): bool { return true; }
}
```

### Fichiers a creer
- `src/SandraCore/Driver/PostgreSQLDriver.php`

### Fichiers a modifier
- `src/SandraCore/SandraDatabaseDefinition.php` — adapter les CREATE TABLE pour PostgreSQL
- `src/SandraCore/DatabaseAdapter.php` — gerer les specificites PostgreSQL
- `tests/MultiDatabaseTest.php` — ajouter les tests PostgreSQL

### Impact
- Support des deployements PostgreSQL (populaire dans l'industrie)
- Meilleures performances analytiques (JSONB, window functions)
- Full-text search natif sans dependance externe

---

## Resume et Roadmap suggeree

### Phase 5 - Operations & Robustesse (priorite maximale)
1. Operations Batch — performances critiques pour l'import
2. Controle de concurrence — integrite des donnees
3. Systeme de migration — deployements en production

### Phase 6 - Expressivite des requetes
4. Requetes avancees (OR, aggregation) — confort developpeur
5. API REST avancee (PATCH, bulk, curseur) — API production-ready
6. Recherche full-text avancee — scalabilite

### Phase 7 - Analyse & Interoperabilite
7. Algorithmes de graphe — analyse avancee
8. Export multi-format — interoperabilite
9. Driver PostgreSQL — couverture base de donnees

### Phase 8 - Enterprise
10. Middleware/Plugins — extensibilite
11. Observabilite/Metriques — operations
12. Snapshot/Time-travel — audit et compliance
