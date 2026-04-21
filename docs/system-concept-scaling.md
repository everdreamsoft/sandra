# System Concept Scaling: Analysis & Strategy

## How System Concepts Work Today

Sandra maps every intellectual concept to a unique ID with a human-readable shortname. This is the core design that makes Sandra AI-native — the shortname is the bridge between human language and the graph.

At boot, `SystemConcept::load()` executes:

```sql
SELECT id, shortname FROM {concept_table} WHERE shortname != ''
```

This loads **every** concept into two PHP arrays in RAM:
- `_conceptsByTable[shortname] → id` (exact match)
- `_conceptsByTableUnsensitive[lowercase_shortname] → id` (case-insensitive)

When the system needs a concept ID, it does an O(1) array lookup. No SQL query needed. This is fast and elegant.

## Why It Works Today

With ~50 system concepts, the RAM footprint is negligible and the initial query is instant.

The `get()` method (line 144 of SystemConcept.php) already has a partial lazy mechanism: if a concept isn't found in RAM, it falls back to a single-row DB query. But `load()` (line 330) is called on first access and pulls everything at once.

## The Growth Profile of System Concepts

Key insight: **system concepts grow with vocabulary, not with data**.

```
10 person entities   → concepts: name, email, phone, person, person_file, is_a...
1000 person entities → same concepts (zero new ones)
```

What creates new system concepts:
- New **factories** (entity types) → slow growth
- New **reference field names** → slow growth
- New **verbs** (relationship types) → slow growth
- New **abstract concepts** (tags, labels, preferences) → this can grow fast

The risk scenario is an AI agent that creates a new concept per piece of information instead of reusing existing vocabulary:

```
Bad:  likes_chocolate(141), likes_strawberry(142), likes_pizza(143)...
      → one concept per preference, unbounded growth

Good: likes(140) + triplets: Shaban →likes→ chocolate, →likes→ strawberry
      → one concept, reused for all preferences
```

## Projected Memory & Performance Impact

| System concepts | RAM (2 arrays) | Initial query time | Impact |
|----------------|-----------------|-------------------|--------|
| 50 | ~4 KB | <1ms | None |
| 500 | ~40 KB | ~5ms | None |
| 5,000 | ~400 KB | ~50ms | Negligible |
| 50,000 | ~4 MB | ~500ms | Noticeable boot delay |
| 500,000 | ~40 MB | ~5s | Problematic |

The real bottleneck is **boot time**, not RAM. A 5-second query on every `System` instantiation (which happens on every MCP connection and periodically via `bootFreshSystem()`) would degrade the experience significantly.

## When Would This Actually Happen?

### Unlikely scenarios (stay under 5,000 concepts)
- Personal assistant (one user, one agent) → ~100-500 concepts
- Small team (5-10 people sharing a Sandra instance) → ~500-2,000 concepts
- Single application with structured data → ~200-1,000 concepts

### Possible scenarios (could reach 50,000+)
- Multi-tenant platform where each user's vocabulary pollutes the shared concept table
- AI agent with poor concept hygiene (creates instead of reuses)
- Application that uses concepts as tags where the tag space is large (e.g., one concept per hashtag, per URL, per product SKU)

### Design-level mitigations (no code change needed)
- Document and enforce the principle: **concepts are vocabulary, not data**
- In MCP instructions, tell the LLM: "Always search before creating. Reuse existing concepts."
- Sandra batch tool already encourages reuse via `sandra_search` before `sandra_batch`

## Solutions (Ordered by Effort)

### 1. Lazy Loading (recommended first step)

Remove the eager `load()` call. Let concepts load on demand via `get()` → DB fallback → cache.

**Current flow:**
```
System boot → SystemConcept → first get() call → load() → SELECT ALL → fill RAM → return
```

**Proposed flow:**
```
System boot → SystemConcept → first get("likes") → not in RAM → SELECT WHERE shortname = "likes" → cache it → return
```

The cache fills organically with only the concepts actually used in the current session. A typical MCP session uses 20-50 concepts, not all 50,000.

**Trade-off:** Individual lookups are slightly slower (one SQL query per cache miss instead of batch load). But with MySQL query caching and the fact that most sessions reuse the same small set of concepts, the net effect is faster boot + same runtime performance.

**Effort:** Small. Modify `load()` to be a no-op or remove the initial `listAll()` call. The `get()` fallback mechanism already exists.

### 2. Hot/Cold Concept Split

Maintain two tiers:

```
Hot concepts (always in RAM):
  - Core system: is_a, contained_in_file, deleted, index, creationTimestamp
  - Factory names: person, company, task...
  - Common verbs: likes, works_at, has_module...

Cold concepts (lazy loaded):
  - Everything else
```

**Implementation:** Tag hot concepts in the DB (e.g., a `core` flag column) or maintain a hardcoded list of core shortnames. Load only hot concepts at boot.

**Effort:** Medium. Requires schema change or configuration.

### 3. LRU Cache with Size Cap

Replace the unbounded arrays with a capped cache (e.g., max 1,000 entries). Evict least-recently-used concepts when full.

```php
class ConceptCache {
    private array $cache = [];
    private array $accessOrder = [];
    private int $maxSize;

    public function get(string $shortname): ?int { ... }
    public function put(string $shortname, int $id): void { ... }
    private function evict(): void { ... }
}
```

**Trade-off:** Adds complexity. Evicted concepts need re-fetching from DB. Good for extreme scale but probably overkill for the near term.

**Effort:** Medium-high. New class, integration into SystemConcept.

### 4. Concept Namespacing (long-term, multi-tenant)

If Sandra becomes multi-tenant, concepts should be scoped:

```
Global concepts:  is_a, contained_in_file (shared by all)
User concepts:    user_123:likes, user_123:chocolate (scoped)
```

This prevents one user's vocabulary from polluting another's concept table and keeps the per-user concept count manageable.

**Effort:** High. Requires rethinking the concept table schema and all queries.

## Current approach

For the typical use case (personal AI assistant, small teams), the eager load works well. The concept reuse principle is documented in the MCP instructions so the LLM reuses vocabulary instead of creating concepts per fact.

Lazy loading (solution 1) is the natural next step if an installation crosses ~10,000 concepts — a minimal change that preserves Sandra's architecture, since the `get()` fallback already exists.

Concept count per environment is worth tracking. A sudden jump often indicates pollution (agent creating instead of reusing) rather than legitimate vocabulary growth.

## Key Takeaway

The system concept architecture is a **strength**, not a weakness. The eagerness of `load()` is an optimization for the common case (small vocabulary) that has a known ceiling. The fix (lazy loading) is straightforward and doesn't compromise Sandra's core design principle: every concept is a first-class citizen with a name and an identity.
