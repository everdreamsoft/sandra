
# Sandra Performance Analysis: In-Memory Model & Query Pipeline

## Date: 2026-02-14

---

## Table of Contents

1. [The 2-Query Design](#1-the-2-query-design)
2. [Why This Design Matters](#2-why-this-design-matters)
3. [Concept Flyweight & Memory Profile](#3-concept-flyweight--memory-profile)
4. [Bottlenecks & Fixes](#4-bottlenecks--fixes)
5. [LLM Context Serialization Advantage](#5-llm-context-serialization-advantage)

---

## 1. The 2-Query Design

Sandra's `populateLocal()` executes exactly **2 SQL queries** regardless of entity count or reference count.

### Query 1: Find Entity Concept IDs

Located in `ConceptManager::getConceptsFromLinkAndTarget()`.

```sql
SELECT l.idConceptStart, l.idConceptLink, l.idConceptTarget
FROM Link l
  JOIN Link link1 ON link1.idConceptStart = l.idConceptStart
WHERE l.idConceptLink = {contained_in_file_concept}
  AND l.idConceptTarget = {file_concept}
  AND l.flag != {deletedUNID}
  AND link1.flag != {deletedUNID}
  AND link1.idConceptTarget IN ({is_a_target})
  AND link1.idConceptLink IN ({is_a_verb})
ORDER BY link1.idConceptStart ASC
LIMIT {limit} OFFSET {offset}
```

Each `setFilter()` call adds another `JOIN Link linkN ON linkN.idConceptStart = l.idConceptStart` with conditions. Exclusion filters use `LEFT JOIN ... IS NULL` anti-joins.

Result: array of `idConceptStart` values (entity concept IDs).

### Query 2: Bulk-Fetch ALL References

Located in `ConceptManager::getReferences()`.

```sql
SELECT r.id, r.idConcept, r.linkReferenced, r.value,
       x.idConceptStart, x.idConceptLink, x.idConceptTarget, x.id
FROM Reference r
  JOIN Link x ON x.id = r.linkReferenced
  JOIN Link y ON y.id = r.linkReferenced
WHERE y.idConceptStart IN ({comma_separated_concept_ids})
  AND y.idConceptLink = {contained_in_file_concept}
  AND y.idConceptTarget = {file_concept}
  AND x.flag != {deletedUNID}
```

ALL references for ALL matched entities fetched in a **single query**. The `IN()` clause contains every concept ID from Query 1. The result is flat EAV rows:

```
idConceptStart=42, idConcept=101(name),  value="Mars"
idConceptStart=42, idConcept=102(mass),  value="0.107"
idConceptStart=43, idConcept=101(name),  value="Venus"
idConceptStart=43, idConcept=102(mass),  value="0.82"
```

Pivoted **in PHP** (not SQL) into per-entity associative structure:

```php
$refs[42][101] = "Mars";
$refs[42][102] = "0.107";
$refs[43][101] = "Venus";
$refs[43][102] = "0.82";
```

### Total: 2 queries for any collection size.

`populateBrotherEntities()` follows the same pattern: 1 query fetches ALL brother relationships for ALL entities in the factory. `joinPopulate()` adds 1 triplet query + 1 reference query per joined factory = 3-4 total queries for a full join.

---

## 2. Why This Design Matters

### Graph for structure, EAV for data, bulk retrieval for both

The references aren't stored as individual graph edges to traverse. They're an **EAV table attached to the entity's link**. The graph structure (triplets) handles identity and relationships; references handle data - and both come back in bulk.

### Comparison with other approaches

| Approach | Queries for N entities, M refs each | Notes |
|----------|-------------------------------------|-------|
| **Sandra populateLocal()** | **2** | Flat EAV rows, PHP pivot |
| Neo4j MATCH ... RETURN | 1 | But schema baked into node properties |
| Pure SPARQL triple store | 1 (with N*M triple patterns) | Each property = separate index lookup |
| Naive graph traversal | N + N*M | N+1 problem |
| SQL relational (Eloquent) | 1 per table + N+1 for relations | Eager loading helps |
| Sandra QueryBuilder (ref filter) | N+2 | N ref searches + 2 populateLocal |

Sandra's approach avoids N+1 entirely. The trade-off: CPU work in PHP for pivoting EAV rows into entity structures. This is cheap compared to network round-trips.

### streamEntities() for bounded memory

```php
public function streamEntities(int $chunkSize = 1000): \Generator
```

PHP Generator that calls `populateLocal()` repeatedly with increasing offsets. Each chunk clears internal state before loading the next. For 10,000 entities with chunkSize=1000: 20 queries instead of 2, but peak memory bounded to ~1000 entities. Good for very large datasets.

---

## 3. Concept Flyweight & Memory Profile

### ConceptFactory flyweight

```php
public function getConceptFromId($conceptId)
{
    if (isset($this->conceptMapFromId[$conceptId])) {
        return $this->conceptMapFromId[$conceptId];
    }
    $concept = new Concept($conceptId, $this->system);
    $this->conceptMapFromId[$conceptId] = $concept;
    return $concept;
}
```

If 10,000 entities share the same 20 reference types, only 20 Concept objects exist. Not 200,000.

### SystemConcept bulk load

`SystemConcept::load()` pulls the entire concept table once:

```sql
SELECT id, shortname FROM Concept WHERE shortname != ''
```

Stored as two hash maps (case-sensitive + lowercased). All subsequent `get($shortname)` calls are O(1) in-memory lookups.

### Memory per entity

| Component | Size |
|-----------|------|
| Entity object overhead | ~200 bytes |
| 3 Concept pointers (shared, no duplication) | ~24 bytes |
| Back-references (factory, system) | ~16 bytes |
| entityRefs array with 5 entries (5 Reference objects @ ~80 bytes) | ~400 bytes |
| **Total per entity (5 refs)** | **~640 bytes** |

| Scale | Memory |
|-------|--------|
| 1,000 entities (5 refs each) | ~640 KB |
| 10,000 entities (5 refs each) | ~6.4 MB |
| 100,000 entities (5 refs each) | ~64 MB |
| 10,000 entities (20 refs each) | ~18 MB |

---

## 4. Bottlenecks & Fixes

### FIX 1: Redundant double-JOIN in getReferences() [CRITICAL - EASY WIN]

**File:** `src/SandraCore/ConceptManager.php` (~line 465)

**Problem:** Query 2 joins the Link table TWICE on the same condition:

```sql
-- CURRENT (redundant)
FROM Reference r
  JOIN Link x ON x.id = r.linkReferenced
  JOIN Link y ON y.id = r.linkReferenced   -- SAME JOIN, different alias
WHERE y.idConceptStart IN (...)
  AND y.idConceptLink = ...
  AND y.idConceptTarget = ...
  AND x.flag != ...
```

Tables `x` and `y` point to the exact same row. `x` is used for flag checking and reading columns. `y` is used for filtering. They can be consolidated.

**Fix:**

```sql
-- FIXED (single join)
FROM Reference r
  JOIN Link x ON x.id = r.linkReferenced
WHERE x.idConceptStart IN (...)
  AND x.idConceptLink = ...
  AND x.idConceptTarget = ...
  AND x.flag != ...
```

**Impact:** Eliminates one full join of the Link table. For a factory with 10,000 entities and 100,000 reference rows, this halves the join work on the most data-heavy query in the system.

**Risk:** Zero. Same result, fewer joins.

---

### FIX 2: Lazy Reference object construction [MEDIUM - MEMORY WIN]

**File:** `src/SandraCore/Entity.php` (~line 43-61)

**Problem:** Every reference is immediately wrapped in a `Reference` object, even if never accessed. For an entity with 20 references where only 1-2 are read, 18-19 objects are wasted.

**Current:**

```php
// In Entity constructor - creates Reference objects eagerly
foreach ($refsArray as $conceptId => $value) {
    $ref = new Reference(0, $conceptId, $value, $this, $this->system);
    $this->entityRefs[$conceptId] = $ref;
}
```

**Proposed fix:**

```php
// Store raw data, create Reference objects on demand
private array $rawRefs = [];

public function __construct(..., array $refsArray = [])
{
    $this->rawRefs = $refsArray;
}

public function getRef(int $conceptId): ?Reference
{
    if (isset($this->entityRefs[$conceptId])) {
        return $this->entityRefs[$conceptId];
    }
    if (isset($this->rawRefs[$conceptId])) {
        $ref = new Reference(0, $conceptId, $this->rawRefs[$conceptId], $this, $this->system);
        $this->entityRefs[$conceptId] = $ref;
        return $ref;
    }
    return null;
}
```

**Impact:** For read-heavy workloads (display, search, LLM serialization) where entities have many references but only a few are accessed, this cuts Reference object count significantly.

**Risk:** Low. Need to verify all code paths that access `$entity->entityRefs` directly (vs via getter methods).

**Note:** Many code paths access `$entity->entityRefs` array directly. A full audit of access patterns is needed before implementing this. Consider a phased approach: first add the getter method alongside the existing eager loading, migrate callers, then switch to lazy.

---

### FIX 3: Batch INSERT for createNew() [HIGH - WRITE PERFORMANCE]

**File:** `src/SandraCore/EntityFactory.php` (~line 954+), `src/SandraCore/DatabaseAdapter.php`

**Problem:** `createNew()` with 10 references fires ~13 individual INSERTs:
- 1 INSERT for the concept
- 2 INSERTs for the is_a and contained_in triplets
- 10 INSERTs for references

Bulk-creating 1,000 entities = **13,000 individual INSERT statements**.

**Proposed fix:** Add a `createMany()` method that batches:

```php
public function createMany(array $entitiesData): array
{
    // 1. Batch-insert concepts
    //    INSERT INTO Concept (code, shortname) VALUES (...), (...), (...)

    // 2. Batch-insert triplets (is_a + contained_in per entity)
    //    INSERT INTO Link (...) VALUES (...), (...), (...)

    // 3. Batch-insert references
    //    INSERT INTO Reference (...) VALUES (...), (...), (...)

    // All wrapped in a single transaction
}
```

**Impact:** For 1,000 entities with 10 refs: 3 batch INSERTs instead of 13,000 individual ones. ~1000x fewer round-trips.

**Risk:** Medium. Need to handle auto-increment ID retrieval for batch inserts (use `LAST_INSERT_ID()` + row count), and ensure concept deduplication still works.

---

### FIX 4: QueryBuilder ref+brother intersection [MEDIUM - QUERY PERFORMANCE]

**File:** `src/SandraCore/Query/QueryBuilder.php`

**Problem:** When combining `whereRef` and `whereHasBrother`, the system:
1. Runs brother filters via SQL JOINs (efficient, gets all matching entities)
2. Loads ALL brother-matching entities into memory
3. Runs ref SQL queries separately
4. Intersects results in PHP with `array_filter`

For 100,000 entities where 10 match the ref filter, this loads 100,000 entities to keep 10.

**Proposed fix:** Run ref SQL first to get candidate concept IDs, then inject those IDs as an additional constraint in the brother query:

```php
// 1. Run ref queries first → get candidate IDs [42, 57, 89]
$refMatchIds = $this->executeRefQueries();

// 2. Add to the main query as a constraint
// WHERE ... AND l.idConceptStart IN (42, 57, 89)
$this->conceptManager->setPreFilterIds($refMatchIds);

// 3. populateLocal() only loads matching entities
```

**Impact:** Dramatic improvement for selective ref filters on large factories. Instead of loading N entities and filtering to M, only load M.

**Risk:** Low-Medium. Need to handle the case where ref query returns empty set (short-circuit to empty result).

---

### FIX 5: Parameterized queries in ConceptManager [LOW - MYSQL PLAN CACHING]

**File:** `src/SandraCore/ConceptManager.php`

**Problem:** SQL built with inline integer values:

```php
$sql = "... WHERE l.idConceptLink = $linkConcept ...";
$stmt = $this->pdo->prepare($sql);
$stmt->execute();
```

Each unique combination of concept IDs generates a new prepared statement. MySQL can't reuse query plans.

**Proposed fix:**

```php
$sql = "... WHERE l.idConceptLink = :linkConcept ...";
$stmt = $this->pdo->prepare($sql);
$stmt->execute([':linkConcept' => $linkConcept]);
```

**Impact:** Small per-query improvement. Adds up for applications that create many factories with different types. Also improves security posture (even though current int-casting is safe).

**Risk:** Very low. Standard PDO best practice.

---

### FIX 6: refId always 0 from populateLocal [LOW - DATA INTEGRITY]

**File:** `src/SandraCore/Entity.php` (~line 56)

**Problem:** When entities are loaded via `populateLocal()`, the Reference `$refId` is always set to `0`:

```php
$ref = new Reference(0, $conceptId, $value, ...);
```

The actual reference table row ID is available in the Query 2 result set (`r.id`) but is discarded during the pivot step.

**Proposed fix:** Preserve `r.id` through the pivot and pass it to the Reference constructor:

```php
// In ConceptManager pivot loop, also store ref IDs
$refs[$entityId][$conceptId] = ['value' => $value, 'refId' => $refRowId];

// In Entity constructor
$ref = new Reference($refsArray[$conceptId]['refId'], $conceptId, ...);
```

**Impact:** Enables `Reference::reload()` and `Reference::hasChangedFromDatabase()` to work correctly for populated entities. Required for reliable conflict detection in concurrent writes.

**Risk:** Low but touches the data flow between ConceptManager and Entity. Needs careful testing.

---

## 5. LLM Context Serialization Advantage

The 2-query bulk pattern is ideal for LLM serialization. When you need to dump a factory's contents into a context window:

1. `populateLocal()` → 2 queries, all data in memory
2. Iterate entities, serialize compactly:

```
Mars: mass[earth]=0.107, revolution[day]=687, radius[km]=3389
Venus: mass[earth]=0.82, revolution[day]=225
Earth: mass[earth]=1, revolution[day]=365, rotation[h]=24
```

No N+1 surprises, no lazy loading triggers, no additional queries. The data is already structured for both tabular display and compact text serialization.

### Token efficiency comparison

For 100 entities with 5 references each:

| Format | ~Tokens | Ratio |
|--------|---------|-------|
| Verbose JSON (`{"entities": [{"name": "Mars", ...}]}`) | ~3,500 | 1x (baseline) |
| Compact JSON (`[{"n":"Mars","m":0.107}]`) | ~1,800 | 0.51x |
| Markdown table | ~1,200 | 0.34x |
| Sandra compact triplet notation | ~900 | 0.26x |

Sandra's internal structure (flat references per entity) maps directly to the most token-efficient serialization formats. No transformation layer needed - just iterate and print.

---

## Implementation Priority

| Fix | Impact | Effort | Risk | Priority |
|-----|--------|--------|------|----------|
| FIX 1: Remove redundant JOIN | High (query speed) | 30 min | Zero | **Do first** |
| FIX 3: Batch INSERT | High (write speed) | 1-2 days | Medium | **Phase 1** |
| FIX 4: QueryBuilder intersection | High (query speed) | 1 day | Low-Medium | **Phase 1** |
| FIX 2: Lazy Reference objects | Medium (memory) | 1-2 days | Low (with audit) | **Phase 2** |
| FIX 5: Parameterized queries | Low (plan cache) | 2 hours | Very low | **Phase 2** |
| FIX 6: Preserve refId | Low (data integrity) | 4 hours | Low | **Phase 2** |
