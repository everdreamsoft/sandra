ok # Sandra Backend Architecture & LLM Strategy Research

## Date: 2026-02-14

---

## Table of Contents

1. [Sandra Current Architecture](#1-sandra-current-architecture)
2. [Backend Approach Evaluation](#2-backend-approach-evaluation)
3. [PHP Admin Panel Frameworks Research](#3-php-admin-panel-frameworks-research)
4. [Recommended Backend Strategy](#4-recommended-backend-strategy)
5. [Sandra for LLMs: Research & Opportunity](#5-sandra-for-llms-research--opportunity)
6. [Graph RAG Landscape](#6-graph-rag-landscape)
7. [Knowledge Graphs & LLM Context Optimization](#7-knowledge-graphs--llm-context-optimization)
8. [Competitor Comparison](#8-competitor-comparison)
9. [Priority Matrix & Roadmap](#9-priority-matrix--roadmap)
10. [Sources](#10-sources)

---

## 1. Sandra Current Architecture

### Assets Already Built

| Component | Status | Location |
|-----------|--------|----------|
| REST API (CRUD, search, pagination, relations) | Built | `src/SandraCore/Api/ApiHandler.php` |
| Query Builder (fluent, with operators) | Built | `src/SandraCore/Query/QueryBuilder.php` |
| Validation (rules, custom validators) | Built | `src/SandraCore/Validation/Validator.php` |
| CSV Export/Import | Built | `src/SandraCore/Export/` + `Import/` |
| Graph Traversal (BFS/DFS) | Built | `src/SandraCore/Graph/GraphTraverser.php` |
| Event System | Built | `src/SandraCore/Events/EventDispatcher.php` |
| Multi-DB Drivers (MySQL, SQLite) | Built | `src/SandraCore/Driver/` |
| Search | Built | `src/SandraCore/Search/BasicSearch.php` |
| Caching (Memory, Null) | Built | `src/SandraCore/Cache/` |
| Transaction Manager (nested) | Built | `src/SandraCore/TransactionManager.php` |
| Display/Output formatting | Built | `src/SandraCore/displayer/` |
| CLI / Console | Missing | - |
| Admin UI | Missing | - |
| MCP Server | Missing | - |
| LLM Integration | Missing | - |

### Key Architectural Properties

- **Zero external dependencies** (only `ext-json` and PHPUnit for dev)
- **~10,059 lines** of clean PHP with `declare(strict_types=1)`
- **PHP >= 8.0** required
- **5 tables per environment**: Concepts, Triplets, References, DataStorage, Config
- **Multi-tenant via `env` prefix** - complete isolation per application
- **Driver abstraction** - MySQL and SQLite supported, extensible via `DatabaseDriverInterface`

### Existing REST API (ApiHandler)

Sandra already has a production-ready API layer:

```php
$api = new ApiHandler($system);

$api->register('animals', $animalFactory, [
    'read' => true,
    'create' => true,
    'update' => true,
    'delete' => true,
    'searchable' => ['name', 'species'],
    'brothers' => ['owns', 'feeds'],
    'joined' => ['habitat' => $habitatFactory],
]);

$request = new ApiRequest('GET', '/animals/123');
$response = $api->handle($request);
echo $response->toJson();
```

Supported operations:
- `GET /resource` - List with pagination
- `GET /resource?search=query&limit=50&offset=0` - Search
- `GET /resource/{id}` - Single entity
- `POST /resource` - Create with brothers/joined entities
- `PUT /resource/{id}` - Update
- `DELETE /resource/{id}` - Soft delete

### Extension Points for Backend

1. **REST API Layer** - Build upon existing `ApiHandler`
2. **Custom Display Formats** - Extend `DisplayType` interface
3. **Query Builders** - Use existing `QueryBuilder` fluent interface
4. **Form Validation** - Use `Validator` with custom rules
5. **Search** - Extend `BasicSearch`
6. **Event Hooks** - Use `EventDispatcher` for UI reactions
7. **Database Drivers** - Implement `DatabaseDriverInterface`
8. **Export Formats** - Implement `ExporterInterface`
9. **CLI Commands** - Create command classes using System/Factory directly
10. **Plugin System** - `InnateSkills/` provides a skill architecture (Cartographer, Explainer, Gossiper, etc.)

---

## 2. Backend Approach Evaluation

### Option A: Embedded UI in Sandra Package (Telescope Pattern)

**How it works:** Ship an admin panel inside `src/SandraCore/Admin/` that mounts at `/sandra-admin`. The admin consumes Sandra's own `ApiHandler`. Built with something lightweight (Alpine.js + HTMX, or a compiled SPA).

| Criterion | Rating | Notes |
|-----------|--------|-------|
| Effort | Medium-High | Frontend dev required, but API already exists |
| Graph DB fit | Excellent | Full control over triplet/concept/graph UI elements |
| Distribution | Excellent | `composer require` and you have an admin |
| Maintenance | Risk | UI maintenance can dominate core library dev time |
| Community | Low | Self-maintained |

**Score: 7.5/10**

**Pros:**
- Ships with the package - zero external dependencies for users
- Deeply integrated with Sandra's graph model
- Can expose graph-specific features (triplet browser, concept navigator, reference editor)

**Cons:**
- Significant frontend development effort
- Must maintain UI alongside core library
- Risk of scope creep

### Option B: Separate Library (`sandra/admin`)

**How it works:** A second Composer package that depends on `everdreamsoft/sandra`. Contains the admin UI and CLI tools. Sandra core stays lean.

| Criterion | Rating | Notes |
|-----------|--------|-------|
| Effort | Medium-High | Same frontend work, but decoupled releases |
| Graph DB fit | Excellent | Same control as Option A |
| Distribution | Good | `composer require sandra/admin` |
| Maintenance | Good | Core and admin evolve independently |
| Community | Low-Moderate | Easier for contributors (separate repo) |

**Score: 8/10**

**Pros:**
- Best separation of concerns
- Sandra core stays lean and dependency-free
- Independent release cycles
- Easier for community contributions

**Cons:**
- Two packages to maintain
- Version synchronization concerns

### Option C: Laravel + `composer require sandra`

**How it works:** Use Filament v4 (which now supports custom data sources without Eloquent) or Orchid to build admin panels backed by Sandra factories.

| Criterion | Rating | Notes |
|-----------|--------|-------|
| Effort | Medium | Filament v4 custom data sources reduce impedance mismatch |
| Graph DB fit | Good | Works but fights relational assumptions |
| Distribution | Good | Standard Laravel ecosystem |
| Maintenance | Good | Filament community handles UI bugs |
| Community | Excellent | 18k+ stars, huge ecosystem |

**Score: 7/10**

**Pros:**
- Largest community and ecosystem
- Filament v4's custom data sources work without Eloquent
- Mature, battle-tested UI components
- Authentication, authorization, middleware built-in

**Cons:**
- Adds heavy Laravel framework dependency
- Sandra currently has zero framework dependencies (a design asset)
- Relational assumptions baked into the framework at many levels
- Graph-specific UI (triplet browser, traversal visualization) requires custom Filament components

### Option D: Sandra API + JS Admin Frontend (Decoupled)

**How it works:** Enhance the existing `ApiHandler` with graph introspection endpoints (list factory types, describe schemas, list relationships). Put a React Admin / Refine / Vue Admin frontend on top.

| Criterion | Rating | Notes |
|-----------|--------|-------|
| Effort | Low-Medium | API exists, introspection is the main new work |
| Graph DB fit | Excellent | Frontend designed specifically for graph concepts |
| Distribution | Moderate | Two artifacts to deploy (PHP + JS) |
| Maintenance | Good | Standard JS tooling, API stays stable |
| Community | Good | React Admin has 25k+ stars |

**Score: 8.5/10**

**Pros:**
- Fastest path to a working admin (API already exists)
- Complete frontend freedom - design for graphs, not tables
- API and UI can evolve independently
- Multiple frontend options (React Admin, Refine, custom)

**Cons:**
- Two deployment artifacts
- Requires JS/Node tooling in addition to PHP

### Final Ranking

| Rank | Approach | Score | Best For |
|------|----------|-------|----------|
| 1 | **D: ApiHandler + JS Frontend** | 8.5/10 | Fastest path to a working admin |
| 2 | **B: Separate `sandra/admin` package** | 8/10 | Long-term maintainability |
| 3 | **A: Embedded in Sandra** | 7.5/10 | "Batteries included" experience |
| 4 | **C: Laravel + Filament** | 7/10 | Laravel-centric users |

---

## 3. PHP Admin Panel Frameworks Research

### Laravel Ecosystem

#### Filament v4 (Open Source, Most Promising)

Filament v4 introduced **custom data sources** - you no longer need an Eloquent model to power a table. You can feed tables from arrays, raw queries, or external APIs while keeping full search, sorting, filters, bulk actions, and pagination.

**Fit for Sandra:**
- Custom data source feature means `EntityFactory::populateLocal()` results can feed Filament tables directly
- Forms could map to Sandra's reference arrays
- Triplet model could be represented as nested relation panels
- Still requires Laravel as host framework

#### Laravel Nova ($199/site)

**Fit for Sandra:** Poor. Most tightly coupled to Eloquent. One model = one resource = one table. Sandra's graph model doesn't map to Nova's resource concept. Customization notoriously difficult.

#### Backpack for Laravel

**Fit for Sandra:** Moderate. More customizable than Nova, CRUD operations are overridable, but still fundamentally assumes Eloquent models.

#### Orchid (Open Source)

**Fit for Sandra:** Moderate-Good. Screen-based system rather than model-based. You define screens with layouts that can pull data from any source. More compatible with Sandra's non-traditional data model.

### Standalone Solutions

#### AdminLTE + Custom PHP

Pure HTML/CSS/JS template. Maximum flexibility, maximum effort. You build all CRUD operations using Sandra's `EntityFactory` and `ApiHandler` directly.

#### Adminer

Single-file database admin with plugin system and custom drivers. Operates at raw SQL level - would show Sandra's four tables as flat tables, losing all graph semantics. Instructive architecture pattern but wrong abstraction level.

### API-First Approaches

#### API Platform (Symfony)

Expects Doctrine entities or DTOs. Large abstraction gap with Sandra's graph model. Would require custom State Providers and State Processors.

#### Sandra's Own ApiHandler

Already built and already speaks Sandra's native concepts. Supports CRUD, pagination, search, brother entities, joined factories.

### Console/CLI Approaches

#### Symfony Console (Standalone) - Recommended

- No framework dependency (Sandra currently has zero)
- Mature, well-documented
- Features: styled output, table rendering, progress bars, interactive prompts, autocomplete
- Each command is a self-contained class

Potential commands:
```
sandra:factory:list          - List all registered factories
sandra:entity:browse <factory>   - Browse entities in a factory
sandra:concept:search <term>     - Search concepts
sandra:triplet:inspect <id>      - Show all triplets for a concept
sandra:graph:traverse <start> <verb> - Traverse the graph
sandra:schema:install            - Create Sandra tables
sandra:export:csv <factory>      - Export factory to CSV
sandra:import:csv <factory> <file>  - Import from CSV
```

#### Laravel Zero

Micro-framework for console apps built on Laravel components. Good DX but adds a Laravel dependency.

### Headless CMS Patterns

#### Directus Pattern (Database-First) - Most Relevant

Directus introspects an existing database schema and auto-generates REST + GraphQL APIs. The pattern adapted to Sandra:

1. Instead of SQL schema introspection, introspect Sandra's concept graph
2. Query all distinct `is_a` types to discover "entity types"
3. Query all distinct `contained_in_file` concepts to discover "collections"
4. Query reference concepts to discover "fields" per entity type
5. Query triplet verbs to discover "relationships" between entity types
6. Auto-generate admin interfaces from introspected graph structure

Sandra's fixed 4-table schema with dynamic graph structure is actually **easier** to introspect than arbitrary SQL schemas.

---

## 4. Recommended Backend Strategy

### Phase 1: CLI Tool (Lowest effort, highest immediate value)

Add `symfony/console` as a dependency. Build commands for factory browsing, entity inspection, graph traversal, and bulk operations. Sandra already has all the internals (`GraphTraverser`, `BasicSearch`, `CsvExporter`, `CsvImporter`).

**Effort:** Low
**Value:** High for developers

### Phase 2: Self-Describing API

Add graph introspection endpoints to `ApiHandler`:
- List all factory types (query distinct `is_a` concepts)
- Describe a factory's reference schema
- Describe relationship verbs between types
- Graph structure metadata

This makes Sandra self-describing at runtime.

**Effort:** Medium
**Value:** High (enables any frontend to auto-generate interfaces)

### Phase 3: Admin UI as Separate Package

Build `sandra/admin` as a separate Composer package:
- Consumes the self-describing API from Phase 2
- Lightweight JS framework (Alpine.js + HTMX for simplicity, or React Admin/Refine for power)
- Graph-specific UI: triplet browser, concept navigator, relationship editor, traversal visualizer
- Follows the Telescope service provider pattern for easy mounting

**Effort:** High
**Value:** High (makes Sandra accessible to non-developers)

### Phase 4 (Optional): Laravel Integration Package

If Laravel adoption is important, release `sandra/filament` that provides Filament v4 resources backed by Sandra factories using custom data sources.

**Effort:** Medium
**Value:** Medium (targets Laravel-specific audience)

---

## 5. Sandra for LLMs: Research & Opportunity

### The Core Thesis

The AI field is converging on a clear insight: **vector search alone is insufficient for complex reasoning**. The emerging consensus (VentureBeat, Microsoft Research, Neo4j, academic papers at ICLR/ACL 2025-2026) is that structured knowledge graphs significantly improve LLM capabilities for multi-hop reasoning, synthesis queries, and explainable AI.

Sandra's triplet model (`subject-verb-object` with typed references) maps directly to the knowledge graph representations that modern GraphRAG systems build from scratch. This is a structural advantage.

### Sandra's Natural Advantages for LLMs

#### 1. Native Triplet Format = LLM-Ready Context

```
Sandra internal:  [Mars] --is_a--> [planet], refs: {mass[earth]: 0.107}
LLM-optimized:    Mars -[is_a]-> planet (mass[earth]=0.107)
```

This compact serialization is more token-efficient than JSON (40-70% savings on structural syntax) and more structured than prose. Research shows graph-based context selection achieves higher information density per token than chunk-based retrieval.

#### 2. Built-in Graph Traversal = Ready for GraphRAG

Sandra's `GraphTraverser` (BFS/DFS with cycle detection, depth control) already does what GraphRAG frameworks build from scratch - traverse relationships to build connected subgraphs of relevant context.

#### 3. Ontological Typing = Schema for LLM Tool Use

Sandra's `is_a` / `contained_in` typing system provides exactly the kind of schema that MCP servers need to expose. An LLM agent can discover: "there are factories for `planet`, `star`, `article` - each with these reference fields and these relationship verbs."

#### 4. Multi-Environment Isolation = Multi-Tenant RAG

Sandra's `env` prefix system means isolated knowledge graphs per user/tenant/domain without database separation. Critical for multi-tenant AI applications.

#### 5. P2P Gossip Sync = Federated Knowledge

Sandra's Gossiper protocol enables knowledge graph synchronization between peers - a capability no other lightweight graph database offers. This enables federated AI knowledge systems.

#### 6. Zero-Ops Deployment

No Java, no Docker, no managed service. Just `composer require` on any PHP host with MySQL/SQLite. This dramatically lowers the barrier to entry for GraphRAG compared to Neo4j or TigerGraph.

---

## 6. Graph RAG Landscape

### Microsoft GraphRAG

Microsoft's GraphRAG (accepted at ICLR 2026) extracts knowledge graphs from raw text, builds community hierarchies of entity clusters, generates summaries, and uses these structures at query time.

**Benchmark Results:**

| Query Type | Vector RAG | GraphRAG | Winner |
|-----------|------------|----------|--------|
| Simple factual lookup | Good | Comparable | Tie |
| Multi-entity queries (5+ entities) | ~0% accuracy | 90%+ accuracy | GraphRAG (3.4x) |
| Global synthesis ("top themes across docs") | Fails | Works | GraphRAG |
| Multi-hop reasoning | Degrades with hops | Maintains | GraphRAG |

**Important caveat:** A systematic evaluation (arxiv 2502.11371) found that GraphRAG frequently underperforms vanilla RAG on many local-retrieval tasks. The gains are strongly task-dependent.

### Think-on-Graph 2.0 (ICLR 2025)

Hybrid RAG framework where the LLM iteratively traverses a knowledge graph using beam search. Key finding: **graph structure as a reasoning scaffold can substitute for model scale** - GPT-3.5 + graph traversal achieves SOTA on 6 of 7 knowledge-intensive datasets, elevating smaller models to GPT-3.5-level performance.

### Paths-over-Graph (ACM WWW 2025)

Outperforms Think-on-Graph by 18.9% average accuracy improvement. GPT-3.5-Turbo + PoG surpasses GPT-4 + ToG by up to 23.9%.

### REKG-MCTS (ACL 2025 Findings)

Combines Monte Carlo Tree Search with knowledge graph traversal for reinforced LLM reasoning.

### Key Insight: Triplets Help LLMs Reason Better

The evidence consistently shows improvements for multi-hop reasoning, entity-dense queries, and consistency/faithfulness metrics. Triplets provide explicit relational structure that LLMs otherwise must infer implicitly from unstructured text, which is error-prone.

Composite triplets (enriched with fine-grained attributes and contextual dependencies - which Sandra supports via references on triplets) provide higher semantic density per token than simple triplets.

---

## 7. Knowledge Graphs & LLM Context Optimization

### Token Efficiency Research

- **Serialization overhead:** Poor data serialization (verbose JSON) consumes 40-70% of available tokens through formatting overhead. For an 8K context window, JSON structure alone can consume 3,000-4,000 tokens.

- **Optimal serialization formats for LLMs (ranked by token efficiency):**
  1. Natural language sentences - highest token efficiency for simple facts
  2. Compact triplet notation: `entity1 -[relation]-> entity2` - concise and LLM-friendly
  3. Markdown tables - good for tabular relationships
  4. JSON/JSON-LD - most verbose, worst token efficiency

- **Graph-based context selection outperforms chunk-based:** Graph traversal retrieves a connected subgraph of relevant entities, achieving higher information density per token than top-K similarity retrieval.

### RDF/OWL + LLMs Convergence

- **LLMs for RDF KG Construction** (Frontiers in AI, 2025): GPT-4o achieves 93.75% precision and 96.26% F1 on medical ontology mapping to RDF.
- **Accelerating Ontology Engineering with LLMs** (ScienceDirect, 2025): LLMs as "intelligent ontology assistants" that translate natural language into OWL ontologies.
- **Semantic Web to Agentic AI** (arxiv 2507.10644): Argues multi-agent systems and formal ontologies share a common lineage, with ontologies providing the interoperability layer.

**Emerging consensus:** Both explicit ontologies (like Sandra's triplet model) and implicit LLM understanding are needed. Ontologies provide formal constraints and interoperability; LLMs provide flexible natural language understanding. This is "neural-symbolic integration."

### Neo4j + LLM Ecosystem

Neo4j has invested $100M to position as the "default knowledge layer" for AI:

- **LangChain integration** (v0.8.0, Jan 2026): `Neo4jGraph`, `GraphCypherQAChain`, `Neo4jVector`
- **LlamaIndex integration**: `KnowledgeGraphIndex`, `Neo4jPropertyGraphStore`, `Neo4jVectorStore`
- **MCP Servers** (`mcp-neo4j`): `mcp-neo4j-cypher`, `mcp-neo4j-data-modeling`, `mcp-neo4j-memory`
- **LLMGraphTransformer**: Converts unstructured text to knowledge graphs using LLMs

### MCP (Model Context Protocol)

Introduced by Anthropic (Nov 2024), adopted by OpenAI (March 2025). Tens of thousands of MCP servers built. The pattern: agent receives question -> uses MCP tools to traverse knowledge graph -> assembles structured context -> generates answer.

Graph databases fit naturally: expose `find_related_entities`, `traverse_relationship`, `execute_query` as MCP tools.

### Agent Memory with Knowledge Graphs

**Graphiti/Zep** pattern: temporal knowledge graph as persistent agent memory with three tiers (episode subgraph, semantic entity subgraph, community subgraph) and bi-temporal model. Outperforms MemGPT on Deep Memory Retrieval benchmarks while reducing token costs and latency.

Sandra's triplet model + references + Gossiper sync maps directly to this pattern.

---

## 8. Competitor Comparison

### Vector Databases vs Graph Databases vs Hybrid

| Requirement | Best Approach |
|---|---|
| Simple semantic search | Vector DB (Pinecone, Weaviate) |
| Multi-hop reasoning | Graph DB |
| "What are the themes across all documents?" | GraphRAG |
| Real-time agent memory with temporal awareness | Graphiti/Zep (hybrid) |
| Explainable/auditable retrieval | Graph DB |
| Minimal ops overhead | Managed Vector DB |
| Production at scale with complex queries | Hybrid (graph + vector index) |

### Sandra vs Neo4j for LLM Applications

| Feature | Neo4j | Sandra | Advantage |
|---------|-------|--------|-----------|
| LLM ecosystem maturity | Mature (LangChain, LlamaIndex, MCP) | None yet | Neo4j |
| Deployment complexity | Java/Docker, managed cloud ($$$) | `composer require`, any PHP host | **Sandra** |
| Schema flexibility | Property graph (typed) | Triplet-based (fully dynamic) | **Sandra** |
| Self-hosting cost | Heavy (JVM, 4GB+ RAM) | Lightweight (PHP + MySQL/SQLite) | **Sandra** |
| Scale (>1M entities) | Excellent | Limited (in-memory populate) | Neo4j |
| P2P sync | None built-in | Gossiper protocol | **Sandra** |
| PHP ecosystem | Requires HTTP bridge | Native | **Sandra** |
| Query language | Cypher (mature, standardized) | PHP fluent API | Neo4j |
| Visualization | Built-in browser, Bloom | None | Neo4j |
| Graph algorithms | GDS library (PageRank, etc.) | BFS/DFS only | Neo4j |
| Community size | Massive | Small | Neo4j |

### Strategic Positioning

Sandra should NOT try to compete with Neo4j head-on. Instead, position as:

1. **The lightweight, PHP-native knowledge graph** for LLM applications
2. **Zero-ops GraphRAG** - no Java, no Docker, no managed service
3. **Agent memory store** for PHP-based AI applications
4. **The SQLite of graph databases** - not the most powerful, but the most deployable

---

## 9. Priority Matrix & Roadmap

### Concrete LLM Integration Opportunities

#### A. Sandra MCP Server (HIGH priority)

Build an MCP server exposing Sandra operations as tools:

```
sandra_list_factories       -> discover entity types
sandra_describe_factory     -> get reference schema and relationships
sandra_search(factory, q)   -> search entities
sandra_get_entity(id)       -> get entity with references
sandra_traverse(start, verb, depth) -> graph traversal
sandra_create_entity(factory, refs) -> create entity
sandra_link_entities(a, verb, b, refs) -> create relationship
```

This lets Claude, GPT, and other agents directly interact with Sandra knowledge graphs.

#### B. LLM-Optimized Displayer (QUICK WIN)

Add an "LLM context" display format to the Displayer system:

```php
$display = $factory->getDisplay('llm_context', ['name', 'mass[earth]', 'revolution[day]']);
// Output: "Mercury: mass[earth]=0.06, revolution[day]=88\nVenus: mass[earth]=0.82..."
```

Saves 40-70% tokens vs JSON while preserving semantic content.

#### C. Agent Memory Store Pattern (PHASE 2)

Following Graphiti/Zep, store agent conversation memory as Sandra triplets:
- Entities = people, topics, decisions, facts extracted from conversations
- Triplets = relationships (`Alice -[mentioned]-> ProjectX`)
- References = metadata (`timestamp`, `confidence`, `source_message_id`)

Sandra structurally already does this. Missing piece: LLM-powered extraction layer.

#### D. Full GraphRAG Pipeline (PHASE 3)

1. **Ingest**: LLM extracts entities and relationships from documents -> Sandra triplets
2. **Index**: Sandra's concept graph provides knowledge structure
3. **Retrieve**: Traverse graph (BFS/DFS) to find relevant subgraph
4. **Augment**: Serialize subgraph in compact triplet notation
5. **Generate**: LLM answers using structured context

### Combined Priority Matrix

| Initiative | Impact | Effort | Priority |
|-----------|--------|--------|----------|
| CLI tools (Symfony Console) | High | Low | **Phase 1 - Quick win** |
| LLM-optimized Displayer format | Medium | Low | **Phase 1 - Quick win** |
| Self-describing API introspection | High | Medium | **Phase 1** |
| MCP Server for Sandra | High | Medium | **Phase 2** |
| Agent memory store pattern | High | Medium | **Phase 2** |
| Admin UI (separate `sandra/admin` package) | Medium | High | **Phase 3** |
| Full GraphRAG pipeline | Very High | High | **Phase 3** |
| Laravel/Filament integration package | Medium | Medium | **Phase 4 (optional)** |

### Implementation Order

```
Phase 1 (Foundation)
├── symfony/console CLI tool
├── LLM-optimized display format
└── API introspection endpoints (self-describing schema)

Phase 2 (AI Integration)
├── Sandra MCP Server
├── Agent memory store pattern
└── Compact triplet serialization for context windows

Phase 3 (Products)
├── sandra/admin package (React Admin / Refine frontend)
├── GraphRAG pipeline (ingest -> index -> retrieve -> augment)
└── Graph visualization component

Phase 4 (Ecosystem)
├── sandra/filament (Laravel integration)
├── Python bridge (for LangChain/LlamaIndex)
└── Documentation & tutorials
```

---

## 10. Sources

### Graph RAG & Knowledge Graphs for LLMs

- [Microsoft GraphRAG - GitHub](https://github.com/microsoft/graphrag)
- [Microsoft GraphRAG - Research](https://www.microsoft.com/en-us/research/project/graphrag/)
- [RAG vs. GraphRAG: A Systematic Evaluation (arxiv 2502.11371)](https://arxiv.org/abs/2502.11371)
- [GraphRAG Accuracy Benchmark - FalkorDB](https://www.falkordb.com/blog/graphrag-accuracy-diffbot-falkordb/)
- [When to Use Graphs in RAG (arxiv 2506.05690)](https://arxiv.org/abs/2506.05690)
- [Thinking with Knowledge Graphs (arxiv 2412.10654)](https://arxiv.org/html/2412.10654v1)
- [Think-on-Graph 2.0 - ICLR 2025](https://proceedings.iclr.cc/paper_files/paper/2025/file/830b1abc6d2da85f23d41169fa44d185-Paper-Conference.pdf)
- [Paths-over-Graph - ACM WWW 2025](https://dl.acm.org/doi/10.1145/3696410.3714892)
- [REKG-MCTS - ACL 2025 Findings](https://aclanthology.org/2025.findings-acl.484.pdf)
- [Awesome-GraphRAG](https://github.com/DEEP-PolyU/Awesome-GraphRAG)
- [KG-LLM Papers List](https://github.com/zjukg/KG-LLM-Papers)

### Neo4j & LLM Ecosystem

- [Neo4j $100M AI Investment](https://siliconangle.com/2025/10/02/neo4j-launches-agent-builder-mcp-server-startup-program-backed-100m-investment/)
- [langchain-neo4j on PyPI (v0.8.0)](https://pypi.org/project/langchain-neo4j/)
- [LangChain Neo4j Integration](https://neo4j.com/labs/genai-ecosystem/langchain/)
- [LlamaIndex Neo4j Integration](https://neo4j.com/labs/genai-ecosystem/llamaindex/)
- [Neo4j MCP Servers - GitHub](https://github.com/neo4j-contrib/mcp-neo4j)
- [Neo4j: Knowledge Graph vs Vector DB](https://neo4j.com/blog/genai/knowledge-graph-vs-vectordb-for-retrieval-augmented-generation/)
- [Neo4j LLMGraphTransformer](https://neo4j.com/blog/developer/unstructured-text-to-knowledge-graph/)

### Semantic Web & Ontologies for AI

- [LLMs for RDF KG Construction - Frontiers in AI 2025](https://www.frontiersin.org/journals/artificial-intelligence/articles/10.3389/frai.2025.1546179/full)
- [Accelerating Ontology Engineering with LLMs - ScienceDirect 2025](https://www.sciencedirect.com/science/article/pii/S1570826825000022)
- [From Semantic Web to Agentic AI (arxiv 2507.10644)](https://arxiv.org/html/2507.10644v3)
- [Ontologies, Context Graphs, and Semantic Layers in 2026](https://metadataweekly.substack.com/p/ontologies-context-graphs-and-semantic)
- [Ontology-Enhanced KG Completion (arxiv 2507.20643)](https://arxiv.org/html/2507.20643v2)

### Token Efficiency & Context Optimization

- [Token-Efficient Data Prep for LLMs](https://thenewstack.io/a-guide-to-token-efficient-data-prep-for-llm-workloads/)
- [Innovative Tokenisation of Structured Data (arxiv 2508.01685)](https://www.arxiv.org/pdf/2508.01685)

### Agent Memory & Hybrid Architectures

- [Graphiti/Zep - GitHub](https://github.com/getzep/graphiti)
- [Zep Temporal KG Architecture (arxiv 2501.13956)](https://arxiv.org/abs/2501.13956)
- [HybridRAG - Memgraph](https://memgraph.com/blog/why-hybridrag)
- [FalkorDB](https://www.falkordb.com/)

### MCP & Agentic AI

- [MCP Specification (2025-11-25)](https://modelcontextprotocol.io/specification/2025-11-25)
- [Neo4j MCP Developer Guide](https://neo4j.com/developer/genai-ecosystem/model-context-protocol-mcp/)
- [MCP-Powered Agents for Graph - Hypermode](https://hypermode.com/blog/mcp-powered-agent-graph)
- [Google MCP Toolbox + Neo4j](https://neo4j.com/blog/developer/ai-agents-gen-ai-toolbox/)

### Vector DB Landscape

- [Vector Database Story Two Years Later - VentureBeat](https://venturebeat.com/ai/from-shiny-object-to-sober-reality-the-vector-database-story-two-years-later/)
- [Next Frontier of RAG 2026-2030](https://nstarxinc.com/blog/the-next-frontier-of-rag-how-enterprise-knowledge-systems-will-evolve-2026-2030/)

### PHP Admin Frameworks

- [Filament PHP v4](https://filamentphp.com/)
- [Filament v4 Custom Data Sources](https://www.artisancraft.dev/stop-relying-on-eloquent-models-build-filament-v4-tables-directly-from-apis-arrays-or-raw-queries-and-why-you-should/)
- [Backpack for Laravel Alternatives 2025](https://backpackforlaravel.com/articles/opinions/backpack-laravel-alternatives-2025-top-admin-panels-and-frameworks)
- [Orchid Documentation](https://orchid.software/en/docs/)
- [API Platform](https://github.com/api-platform/api-platform)
- [Symfony Console Component](https://symfony.com/doc/current/components/console.html)
- [Laravel Telescope](https://laravel.com/docs/12.x/telescope)
- [Directus Architecture](https://deepwiki.com/directus/directus)
- [Adminer](https://www.adminer.org/en/)

### LLM + KG Surveys

- [LLM-empowered KG Construction Survey (arxiv 2510.20345)](https://arxiv.org/abs/2510.20345)
- [KG + LLM Fusion - Frontiers in Computer Science 2025](https://www.frontiersin.org/journals/computer-science/articles/10.3389/fcomp.2025.1590632/full)
