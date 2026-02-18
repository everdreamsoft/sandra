There # Sandra Database Viewer - Project Specification

## For: Coding Agent Implementation Reference

---

## PART 1: HOW SANDRA WORKS

This section explains Sandra's architecture so a coding agent can build against it without prior knowledge.

### What Sandra Is

Sandra is a **PHP graph database** built on top of MySQL/SQLite. It stores everything as **semantic triplets** (subject-verb-object) with key-value references attached. It uses only 5 SQL tables but can model any data structure dynamically - no schema migrations, no ALTER TABLE.

### The 5 Tables

All tables are prefixed with the environment name (e.g., `myapp_SandraConcept`).

```
{env}_SandraConcept      -- Node identities (id, code, shortname)
{env}_SandraTriplets     -- Relationships (id, idConceptStart, idConceptLink, idConceptTarget, flag)
{env}_SandraReferences   -- Key-value data on triplets (id, idConcept, linkReferenced, value)
{env}_SandraDatastorage  -- Large text blobs (id, linkReferenced, value)
{env}_SandraConfig       -- System configuration
```

### How an Entity Works

An Entity is NOT a row in a table. It's a **pair of triplets** plus references:

```
Entity "Mars" (conceptId=42):
  Triplet 1: [42] --is_a--> [planet]              (defines the type)
  Triplet 2: [42] --contained_in_file--> [atlasFile]  (defines the collection)
  References on Triplet 2:
    name = "Mars"
    mass[earth] = "0.107"
    revolution[day] = "687"
```

The `is_a` concept is the **entity type**. The `contained_in_file` concept is the **collection/file**. Together they define what an `EntityFactory` manages.

### EntityFactory = Collection Manager

```php
// This factory manages all entities where is_a=planet AND contained_in_file=atlasFile
$planetFactory = new EntityFactory('planet', 'atlasFile', $system);
```

Key operations:

```php
// CREATE - returns an Entity object
$entity = $factory->createNew(['name' => 'Mars', 'mass[earth]' => '0.107']);

// READ - loads all entities of this type into memory (2 SQL queries total)
$factory->populateLocal($limit, $offset);
$entities = $factory->getEntities(); // array of Entity objects

// Find one entity by reference value
$mars = $factory->first('name', 'Mars');

// Get or create
$entity = $factory->getOrCreateFromRef('name', 'Mars');

// UPDATE
$entity->createOrUpdateRef('name', 'newValue');

// DELETE (soft delete via flag)
$entity->delete();
```

### Entity References (The Data)

Each entity holds its data as an associative array of Reference objects:

```php
$entity->entityRefs  // array<int conceptId, Reference>
$entity->get('name') // returns "Mars" (looks up by concept shortname)
$entity->subjectConcept->idConcept  // the entity's concept ID (e.g., 42)
```

A Reference has:
- `$ref->refConcept` - the Concept object for the key (e.g., "name")
- `$ref->refValue` - the string value (e.g., "Mars")
- `$ref->refConcept->getDisplayName()` - human-readable key name

### Relationships Between Entities

**Brother entities** (one-to-many relationships via triplets):

```php
// Create: Mars knows Venus
$mars->setBrotherEntity('orbits', $sun, ['distance[au]' => '1.52']);

// Read: get all entities Mars has relationship 'orbits' with
$brothers = $mars->getBrotherEntitiesOnVerb('orbits');
```

**Joined entities** (cross-factory queries):

```php
$starFactory->joinFactory('illuminates', $planetFactory);
$starFactory->populateLocal();
$starFactory->joinPopulate();
$sun->getJoinedEntities('illuminates'); // returns planet entities
```

### The 2-Query Population Model (CRITICAL FOR BENCHMARKING)

`populateLocal()` always executes exactly **2 SQL queries**, regardless of how many entities exist:

1. **Query 1** - Find entity concept IDs via Link table self-joins (with filters as additional JOINs)
2. **Query 2** - Bulk-fetch ALL references for ALL matched entities via a single `IN()` clause

The flat EAV rows from Query 2 are **pivoted in PHP memory** into per-entity structures. This is the core design: graph structure for identity/relationships, bulk EAV retrieval for data.

`populateBrotherEntities()` and `joinPopulate()` follow the same bulk pattern - 1-2 additional queries each.

### System Configuration

```php
$system = new System(
    env: 'myapp_',           // table prefix (multi-tenant isolation)
    install: true,            // auto-create tables if they don't exist
    dbHost: '127.0.0.1',
    db: 'sandra',
    dbUsername: 'root',
    dbpassword: ''
);
```

Multiple System instances can coexist, each pointing to different databases or using different prefixes.

### Concept Resolution

Every string identifier (like 'planet', 'name', 'is_a') gets resolved to a numeric concept ID. The `SystemConcept` class loads ALL concepts from the database once into a hash map. After that, every lookup is O(1).

```php
$system->systemConcept->get('planet');  // returns concept ID (int)
```

### Existing API Layer

Sandra ships with `SandraCore\Api\ApiHandler` which provides REST endpoints:

```php
$api = new ApiHandler($system);
$api->register('planets', $planetFactory, [
    'read' => true,
    'create' => true,
    'update' => true,
    'delete' => true,
    'searchable' => ['name'],
    'brothers' => ['orbits'],
    'joined' => ['illuminates' => $starFactory],
]);

// Handle HTTP request
$request = new ApiRequest($method, $path, $queryParams, $bodyArray);
$response = $api->handle($request);
echo $response->toJson();
```

Response format:

```json
{
  "data": {
    "items": [
      {
        "id": 42,
        "refs": {"name": "Mars", "mass[earth]": "0.107"},
        "brothers": {"orbits": [{"target": "Sun", "targetConceptId": 10, "refs": {"distance[au]": "1.52"}}]},
        "joined": {"illuminates": [{"id": 50, "refs": {"name": "Phobos"}}]}
      }
    ],
    "total": 100,
    "limit": 50,
    "offset": 0
  },
  "status": 200
}
```

### Package Info

- **Package:** `everdreamsoft/sandra`
- **Namespace:** `SandraCore\`
- **Location:** `/Users/shabanshaame/htdocs/sandra`
- **Dependencies:** PHP >= 8.0, ext-json (zero external deps)
- **License:** MIT

---

## PART 2: PROJECT STRUCTURE

### New Project: `sandra-viewer`

This is a **separate project** that depends on Sandra via Composer. It provides:
1. A PHP backend that extends Sandra's ApiHandler with introspection + benchmarking endpoints
2. A web frontend for browsing Sandra databases

```
sandra-viewer/
├── composer.json              # requires everdreamsoft/sandra
├── package.json               # frontend dependencies
├── backend/
│   ├── public/
│   │   └── index.php          # single entry point (PHP built-in server compatible)
│   ├── src/
│   │   ├── IntrospectionApi.php   # discovers graph structure dynamically
│   │   ├── BenchmarkApi.php       # runs and measures performance benchmarks
│   │   ├── ViewerApiHandler.php   # extends ApiHandler with introspection + benchmark routes
│   │   └── Config.php             # database connection config
│   └── bootstrap.php          # boots Sandra System, registers factories
├── frontend/
│   ├── index.html             # SPA shell
│   ├── src/
│   │   ├── main.js
│   │   ├── api.js             # API client
│   │   ├── components/
│   │   │   ├── FactoryBrowser.js    # main entity table view
│   │   │   ├── EntityDetail.js      # single entity with refs + relationships
│   │   │   ├── ConceptExplorer.js   # browse raw concepts
│   │   │   ├── TripletInspector.js  # browse raw triplets
│   │   │   ├── GraphView.js         # visual graph of relationships
│   │   │   ├── BenchmarkPanel.js    # run and display benchmarks
│   │   │   ├── SearchBar.js
│   │   │   └── Sidebar.js           # navigation: factory list, tools
│   │   └── styles/
│   └── vite.config.js
├── benchmarks/
│   ├── datasets/
│   │   ├── small.json         # 100 entities, 5 refs each
│   │   ├── medium.json        # 1,000 entities, 10 refs each
│   │   ├── large.json         # 10,000 entities, 10 refs each
│   │   └── relations.json     # 1,000 entities with brother relationships
│   └── BenchmarkRunner.php    # executes benchmark suite, returns timing data
└── README.md
```

### Tech Stack

- **Backend:** Plain PHP (no framework). Sandra's System + ApiHandler + new introspection layer.
- **Frontend:** Vanilla JS or lightweight framework (Svelte preferred for small bundle, or React if the agent prefers). Vite for bundling.
- **Dev server:** PHP built-in server (`php -S localhost:8080 backend/public/index.php`) + Vite dev server with proxy for API calls.
- **No database required initially** - can use SQLite driver for zero-config setup.

### composer.json

```json
{
  "name": "everdreamsoft/sandra-viewer",
  "description": "Database viewer and benchmark tool for Sandra Ontologic Datagraph",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": ">=8.0",
    "everdreamsoft/sandra": "dev-master"
  },
  "autoload": {
    "psr-4": {
      "SandraViewer\\": "backend/src/"
    }
  },
  "repositories": [
    {
      "type": "path",
      "url": "../sandra"
    }
  ]
}
```

---

## PART 3: API SPECIFICATION

### Existing Sandra API Routes (via ApiHandler)

These are dynamically registered per factory:

```
GET    /{resource}             → list entities (pagination: ?limit=50&offset=0)
GET    /{resource}?search=q    → search entities
GET    /{resource}/{id}        → single entity
POST   /{resource}             → create entity
PUT    /{resource}/{id}        → update entity
DELETE /{resource}/{id}        → delete entity
```

### New Introspection Routes (to build)

These endpoints make Sandra self-describing so the frontend can auto-generate interfaces:

```
GET /api/introspect/system
  Returns: {
    env: "myapp_",
    tables: { concept: "myapp_SandraConcept", link: "myapp_SandraTriplets", ... },
    conceptCount: 1234,
    tripletCount: 5678,
    referenceCount: 9012
  }

GET /api/introspect/factories
  Returns: [
    {
      name: "planet",
      entityIsa: "planet",
      entityContainedIn: "atlasFile",
      entityIsaConcept: 42,
      entityContainedInConcept: 55,
      entityCount: 8,
      referenceTypes: ["name", "mass[earth]", "revolution[day]", "radius[km]"],
      brotherVerbs: ["orbits", "has_satellite"],
      joinedVerbs: ["illuminates"]
    },
    ...
  ]

  Implementation: Query distinct is_a and contained_in_file pairs from the Link table.
  For each pair, query the reference concepts used by those entities.
  For each pair, query the distinct verb concepts used in triplets.

GET /api/introspect/factory/{isaName}/{fileName}
  Returns: detailed schema for a specific factory type, including:
  - All reference concept names used by entities of this type
  - All verb concepts used in relationships
  - Sample values for each reference
  - Entity count

GET /api/introspect/concepts?search=term&limit=100
  Returns: raw concept list from the concept table.
  [{ id: 1, shortname: "planet", code: "A planet" }, ...]

GET /api/introspect/triplets?subject={id}&verb={id}&target={id}&limit=100
  Returns: raw triplets matching filters.
  [{ id: 1, subject: 42, verb: 5, target: 55, flag: 0 }, ...]

GET /api/introspect/references?entity={conceptId}
  Returns: all references for a specific entity concept ID.
  [{ id: 1, concept: "name", value: "Mars", linkId: 789 }, ...]
```

### Benchmark Routes (to build)

```
POST /api/benchmark/seed
  Body: { dataset: "small" | "medium" | "large" | "relations", env: "bench_" }
  Action: Creates a fresh Sandra system with the specified env prefix,
          loads the dataset JSON, inserts all entities.
  Returns: { entityCount: 1000, refCount: 10000, duration_ms: 1234 }

POST /api/benchmark/run
  Body: {
    env: "bench_",
    suite: "all" | "populateLocal" | "search" | "queryBuilder" | "createNew" | "brothers",
    iterations: 5
  }
  Returns: {
    results: [
      {
        name: "populateLocal(1000 entities, 10 refs)",
        iterations: 5,
        times_ms: [45, 42, 43, 41, 44],
        avg_ms: 43,
        min_ms: 41,
        max_ms: 45,
        memory_peak_bytes: 6400000,
        queries_executed: 2,
        entities_loaded: 1000
      },
      {
        name: "populateLocal(10000 entities, 10 refs)",
        ...
      },
      ...
    ],
    sandra_version: "dev-master",
    php_version: "8.2.0",
    driver: "mysql",
    timestamp: "2026-02-14T12:00:00Z"
  }

GET /api/benchmark/history
  Returns: array of previous benchmark runs (stored as JSON files on disk).
  Used for comparing performance before/after Sandra library changes.

POST /api/benchmark/cleanup
  Body: { env: "bench_" }
  Action: Drops the benchmark tables.
```

---

## PART 4: BENCHMARK SUITE SPECIFICATION

### Purpose

The benchmark system measures Sandra's performance on controlled data subsets. When we modify Sandra's core library (removing the redundant JOIN, adding batch inserts, etc.), we re-run benchmarks on the same datasets to measure the improvement.

### Benchmark Datasets (JSON format)

Each dataset is a JSON file:

```json
{
  "name": "medium",
  "description": "1000 entities with 10 references each",
  "factory": {
    "entityIsa": "benchEntity",
    "entityContainedIn": "benchFile"
  },
  "entities": [
    {
      "refs": {
        "name": "Entity_0001",
        "field1": "value1",
        "field2": "12345",
        "field3": "lorem ipsum dolor sit amet",
        "field4": "2024-01-15",
        "field5": "category_a",
        "field6": "tag1,tag2,tag3",
        "field7": "some longer text value here",
        "field8": "42.5",
        "field9": "active",
        "field10": "https://example.com/item/0001"
      }
    }
  ],
  "relations": [
    {
      "description": "Random brother relationships between entities",
      "verb": "relatedTo",
      "pairs": [[0, 1], [0, 5], [1, 3], [2, 7]],
      "refsOnRelation": { "weight": "0.85", "since": "2024-01-01" }
    }
  ]
}
```

The datasets should be **generated programmatically** by a seed script, not hand-written. The seed script should create:
- `small.json` - 100 entities, 5 refs each, 0 relations
- `medium.json` - 1,000 entities, 10 refs each, 500 relations
- `large.json` - 10,000 entities, 10 refs each, 5,000 relations
- `xl.json` - 50,000 entities, 10 refs each, 25,000 relations (stress test)

### Benchmark Tests

Each test is run N iterations (default 5), measuring:
- Wall clock time (ms)
- Peak memory usage (bytes) via `memory_get_peak_usage(true)`
- Number of SQL queries executed (via a counting wrapper on PDO)

#### Test 1: `seed_entities` (Write Performance)

```php
// Measures: createNew() speed for bulk entity creation
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
foreach ($dataset['entities'] as $entityData) {
    $factory->createNew($entityData['refs']);
}
```

Measured for each dataset size. This is the baseline for FIX 3 (batch inserts).

#### Test 2: `populate_local` (Read Performance - Core)

```php
// Measures: the 2-query pipeline + PHP pivot
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
$factory->populateLocal($limit);
$entities = $factory->getEntities();
```

Run with limit=100, 1000, 10000. This is the baseline for FIX 1 (redundant JOIN) and FIX 5 (parameterized queries).

#### Test 3: `populate_with_brothers` (Read Performance - Relations)

```php
// Measures: populateLocal + populateBrotherEntities
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
$factory->populateLocal();
$factory->populateBrotherEntities();
```

Baseline for FIX 1 (the redundant JOIN affects every getReferences call).

#### Test 4: `search_by_ref` (Search Performance)

```php
// Measures: BasicSearch in-memory search after populate
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
$factory->populateLocal();
$search = new BasicSearch();
$results = $search->search($factory, 'Entity_0500', 50);
```

And also:

```php
// Measures: QueryBuilder ref search (SQL-level)
$qb = $factory->query();
$results = $qb->whereRef('name', '=', 'Entity_0500')->get();
```

#### Test 5: `query_builder_combined` (QueryBuilder with Mixed Filters)

```php
// Measures: combined ref + brother filter (the PHP intersection bottleneck)
$target = $factory->first('name', 'Entity_0001');
$qb = $factory->query();
$results = $qb->whereRef('field5', '=', 'category_a')
              ->whereHasBrother('relatedTo', $target)
              ->limit(50)
              ->get();
```

Baseline for FIX 4 (QueryBuilder intersection optimization).

#### Test 6: `first_by_ref` (Single Entity Lookup)

```php
// Measures: populateLocal + first() for single entity retrieval
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
$factory->populateLocal();
$entity = $factory->first('name', 'Entity_0500');
```

#### Test 7: `join_populate` (Cross-Factory Joins)

```php
// Measures: joinFactory + joinPopulate
$factoryA = new EntityFactory('benchEntity', 'benchFile', $system);
$factoryB = new EntityFactory('benchTarget', 'benchFile', $system);
$factoryA->joinFactory('relatedTo', $factoryB);
$factoryA->populateLocal();
$factoryA->joinPopulate();
```

#### Test 8: `memory_profile` (Memory Measurement)

```php
// Measures: memory usage at different scales
$factory = new EntityFactory('benchEntity', 'benchFile', $system);
$before = memory_get_usage(true);
$factory->populateLocal($limit);
$after = memory_get_usage(true);
// Report: bytes_per_entity = ($after - $before) / count($factory->getEntities())
```

Run at limit=100, 1000, 10000. Baseline for FIX 2 (lazy Reference objects).

### SQL Query Counter

To count actual SQL queries per operation, wrap PDO:

```php
class CountingPDO extends \PDO {
    public int $queryCount = 0;

    public function prepare($statement, $options = []): \PDOStatement {
        $this->queryCount++;
        return parent::prepare($statement, $options);
    }

    public function resetCount(): void {
        $this->queryCount = 0;
    }
}
```

Or hook into Sandra's logger system:

```php
$logger = new BenchmarkLogger();  // implements ILogger, counts query() calls
$system = new System(..., logger: $logger);
```

### Benchmark Result Storage

Store results as JSON files:

```
benchmarks/results/
  2026-02-14_143022_before-fix1.json
  2026-02-15_091500_after-fix1.json
  ...
```

Each file contains the full benchmark output. The frontend can load multiple result files and display comparison charts.

---

## PART 5: FRONTEND SPECIFICATION

### Views

#### 1. Dashboard

- System info: env prefix, database driver, concept/triplet/reference counts
- Factory list with entity counts
- Quick actions: seed benchmark data, run benchmarks
- Recent benchmark results summary

#### 2. Factory Browser

- Left sidebar: list of discovered factories (from introspection)
- Main area: data table showing entities for the selected factory
  - Columns = reference types discovered for this factory
  - Rows = entities
  - Sortable columns, pagination (limit/offset)
  - Search bar (uses BasicSearch via API)
  - Click row to open EntityDetail

#### 3. Entity Detail

- All references as key-value pairs (editable for CRUD)
- Brother relationships section:
  - Grouped by verb
  - Each entry shows target entity name + refs on the relationship
- Joined entities section
- Raw data: show the actual concept ID, triplet IDs, link IDs
- Actions: edit refs, delete entity

#### 4. Concept Explorer

- Raw concept table browser
- Search by shortname
- Click a concept to see all triplets where it appears (as subject, verb, or target)

#### 5. Triplet Inspector

- Raw triplet table browser with filters (subject, verb, target)
- Shows the concept shortnames alongside the IDs for readability
- Click a triplet to see its references

#### 6. Graph View (stretch goal)

- Visual node-edge graph for a selected entity
- Show N levels of relationships
- Uses Sandra's GraphTraverser BFS/DFS behind the API
- Simple canvas or SVG rendering (d3.js force layout or similar)

#### 7. Benchmark Panel

- **Run benchmarks**: select dataset size, number of iterations, click run
- **Results table**: test name, avg/min/max times, memory, query count
- **Comparison view**: select two benchmark result files, show side-by-side with % change
- **History chart**: line graph of avg times per test across benchmark runs
- **Export**: download results as JSON

### UI Requirements

- **Responsive** but primarily desktop-focused (this is a developer tool)
- **Dark/light theme** via CSS variables
- **No login/auth** - this is a local development tool
- **Loading states** for all API calls
- **Error display** for failed API calls
- **Table features**: sortable columns, pagination controls, column resize

---

## PART 6: IMPLEMENTATION PHASES

### Phase 1: Core Viewer (This Sprint)

Build:
1. `backend/public/index.php` - entry point, CORS headers, route dispatch
2. `backend/src/Config.php` - load DB config (from env vars or config file)
3. `backend/src/IntrospectionApi.php` - introspection endpoints
4. `backend/src/ViewerApiHandler.php` - combines ApiHandler + introspection
5. Frontend: Dashboard, Factory Browser, Entity Detail, Search
6. Auto-discovery: frontend calls `/api/introspect/factories` on load, builds the sidebar and table views dynamically

### Phase 2: Benchmarking

Build:
1. `benchmarks/generate-datasets.php` - creates the JSON dataset files
2. `backend/src/BenchmarkApi.php` - seed, run, history, cleanup endpoints
3. `benchmarks/BenchmarkRunner.php` - executes the test suite
4. Frontend: Benchmark Panel with run, results, comparison, history

### Phase 3: MCP Server (Later - Separate Document)

Build an MCP server that exposes Sandra as tools for LLM agents. Separate spec.

### Phase 4: Performance Fixes + Re-Benchmark (Later)

Apply the 6 fixes documented in `05-performance-bottlenecks-and-fixes.md`:
1. Remove redundant double-JOIN in `getReferences()`
2. Lazy Reference object construction
3. Batch INSERT for `createNew()`
4. QueryBuilder ref+brother intersection optimization
5. Parameterized queries in ConceptManager
6. Preserve refId from populateLocal

After each fix, re-run the benchmark suite and compare with baseline results from Phase 2.

---

## PART 7: INTROSPECTION IMPLEMENTATION GUIDE

The key introspection query - discovering all factory types in a Sandra database:

```php
// Find all distinct (is_a, contained_in_file) pairs
// This discovers what EntityFactory types exist in the database

$sql = "
  SELECT
    isa_link.idConceptTarget AS isa_concept,
    file_link.idConceptTarget AS file_concept,
    COUNT(DISTINCT isa_link.idConceptStart) AS entity_count
  FROM {$system->linkTable} isa_link
  JOIN {$system->linkTable} file_link
    ON file_link.idConceptStart = isa_link.idConceptStart
  WHERE isa_link.idConceptLink = {$isaConcept}
    AND file_link.idConceptLink = {$containedInConcept}
    AND isa_link.flag != {$deletedFlag}
    AND file_link.flag != {$deletedFlag}
  GROUP BY isa_link.idConceptTarget, file_link.idConceptTarget
";
```

Then resolve the concept IDs to shortnames via `SystemConcept`.

To discover reference types per factory:

```php
// Find distinct reference concepts used by entities of a specific type
$sql = "
  SELECT DISTINCT r.idConcept
  FROM {$system->tableReference} r
  JOIN {$system->linkTable} l ON l.id = r.linkReferenced
  WHERE l.idConceptStart IN (
    SELECT isa_link.idConceptStart
    FROM {$system->linkTable} isa_link
    JOIN {$system->linkTable} file_link ON file_link.idConceptStart = isa_link.idConceptStart
    WHERE isa_link.idConceptLink = {$isaConcept}
      AND isa_link.idConceptTarget = {$isaTarget}
      AND file_link.idConceptLink = {$containedInConcept}
      AND file_link.idConceptTarget = {$fileTarget}
      AND isa_link.flag != {$deletedFlag}
      AND file_link.flag != {$deletedFlag}
  )
  AND l.idConceptLink = {$containedInConcept}
  AND l.idConceptTarget = {$fileTarget}
";
```

To discover relationship verbs:

```php
// Find distinct verbs used by entities of a specific type (excluding is_a and contained_in_file)
$sql = "
  SELECT DISTINCT l.idConceptLink, COUNT(*) as usage_count
  FROM {$system->linkTable} l
  WHERE l.idConceptStart IN ({$entityConceptIds})
    AND l.idConceptLink NOT IN ({$isaConcept}, {$containedInConcept})
    AND l.flag != {$deletedFlag}
  GROUP BY l.idConceptLink
";
```

### Important Concept IDs

Sandra has built-in system concepts. The critical ones for introspection:

```php
$isaConcept = $system->systemConcept->get('is_a');
$containedInConcept = $system->systemConcept->get('contained_in_file');
$deletedFlag = $system->deletedUNID;
```

These are loaded during System initialization and are always available.

---

## PART 8: RUNNING THE PROJECT

### Development Setup

```bash
# 1. Create project directory alongside sandra
mkdir sandra-viewer && cd sandra-viewer

# 2. Install PHP dependencies (links to local sandra)
composer install

# 3. Install frontend dependencies
npm install

# 4. Start PHP backend (port 8080)
php -S localhost:8080 backend/public/index.php

# 5. Start frontend dev server (port 5173, proxies /api to 8080)
npm run dev

# 6. Open browser at http://localhost:5173
```

### SQLite Zero-Config Mode

For development without MySQL:

```php
// Config.php
$system = new System(
    env: 'viewer_',
    install: true,
    driver: new \SandraCore\Driver\SQLiteDriver(),
    // SQLite creates a file automatically
);
```

### Connecting to an Existing Sandra Database

```php
// Config.php - point to an existing production/staging DB
$system = new System(
    env: 'prod_',        // must match the existing table prefix
    install: false,       // don't create tables, they already exist
    dbHost: '127.0.0.1',
    db: 'existing_sandra_db',
    dbUsername: 'readonly_user',
    dbpassword: 'password'
);
```

The introspection API will discover all factory types automatically.

---

## PART 9: KEY SANDRA API REFERENCE (Quick Reference for Coding Agent)

### System

```php
$system = new System($env, $install, $dbHost, $db, $dbUsername, $dbpassword, $logger, $driver);
$system->env                    // string: table prefix
$system->linkTable              // string: full triplet table name
$system->conceptTable           // string: full concept table name
$system->tableReference         // string: full reference table name
$system->tableStorage           // string: full storage table name
$system->systemConcept->get($shortname)  // int: concept ID from shortname
$system->conceptFactory->getConceptFromId($id)  // Concept object
$system->deletedUNID            // int: the flag value for soft-deleted entities
System::$pdo                    // PDO connection (static)
```

### EntityFactory

```php
$factory = new EntityFactory($entityIsa, $entityContainedIn, $system);
$factory->populateLocal($limit, $offset)     // load entities (2 SQL queries)
$factory->getEntities()                       // array of Entity objects
$factory->first($refName, $refValue)          // find one entity
$factory->createNew($refsArray)               // create entity
$factory->getOrCreateFromRef($refName, $value) // get or create
$factory->update($entity, $refsArray)         // update entity refs
$factory->isPopulated()                       // bool
$factory->setFilter($verb, $targetEntity)     // add filter (before populateLocal)
$factory->joinFactory($verb, $otherFactory)   // register join
$factory->joinPopulate()                      // execute joins
$factory->populateBrotherEntities()           // load brother relationships
$factory->setDefaultLimit($n)                 // set default LIMIT
$factory->getReferenceMap()                   // array of Concept objects (ref types)
$factory->streamEntities($chunkSize)          // Generator for memory-bounded iteration
```

### Entity

```php
$entity->subjectConcept->idConcept    // int: the entity's concept ID
$entity->entityRefs                    // array<int, Reference>
$entity->get($refShortname)            // string: ref value by shortname
$entity->createOrUpdateRef($name, $value)
$entity->delete()                      // soft delete
$entity->setBrotherEntity($verb, $targetEntity, $refsArray)
$entity->getBrotherEntitiesOnVerb($verb)  // array of Entity
$entity->getJoinedEntities($verb)         // array of Entity
```

### Reference

```php
$ref->refConcept              // Concept object (the key)
$ref->refValue                // string (the value)
$ref->refConcept->idConcept   // int
$ref->refConcept->getDisplayName()  // string (shortname)
$ref->refConcept->getShortname()    // string
```

### ApiHandler

```php
$api = new ApiHandler($system);
$api->register($name, $factory, $options);  // register a resource
$api->handle(new ApiRequest($method, $path, $query, $body));  // returns ApiResponse

// ApiRequest
new ApiRequest('GET', '/planets', ['limit' => '50', 'offset' => '0'], []);
new ApiRequest('POST', '/planets', [], ['name' => 'Mars', 'mass' => '0.107']);

// ApiResponse
$response->getStatus()   // int
$response->getData()     // array
$response->getError()    // ?string
$response->toJson()      // string
$response->isSuccess()   // bool
```

### DatabaseAdapter (static methods)

```php
DatabaseAdapter::setStorage($entity, $longTextValue);
DatabaseAdapter::getStorage($entity);  // string
DatabaseAdapter::searchConcept($system, $value, $refConceptId, ...);
DatabaseAdapter::rawCreateConcept($code, $system);
DatabaseAdapter::rawCreateTriplet($subject, $verb, $target, $system);
DatabaseAdapter::rawCreateReference($tripletId, $conceptId, $value, $system);
DatabaseAdapter::getAllocatedMemory($system);  // array of table sizes in bytes
```

### Search

```php
$search = new BasicSearch();
$search->search($factory, $queryString, $limit);           // all fields
$search->searchByField($factory, $fieldName, $query, $limit);  // specific field
```

### Graph Traversal

```php
$traverser = new GraphTraverser($system);
$result = $traverser->bfs($entity, $verb, $maxDepth);
$result = $traverser->dfs($entity, $verb, $maxDepth);
$result->getEntities();
$result->getEntitiesAtDepth($n);
$result->hasCycle();
```

### Drivers

```php
new \SandraCore\Driver\MySQLDriver();
new \SandraCore\Driver\SQLiteDriver();
```
