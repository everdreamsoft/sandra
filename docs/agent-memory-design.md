# Sandra Agent Memory

## Vision

A self-hosted, graph + vector, MCP-native memory layer that gives any LLM persistent, relational, semantic memory.

No AI agent today has a satisfying long-term memory. Sandra addresses this directly.

## The Problem

Every AI agent suffers from amnesia:
- Conversations reset. Context is lost.
- Text-based memory (LangChain, etc.) stores blobs with no structure.
- Vector-only databases find similar text but don't understand relationships.
- Enterprise graph databases are too heavy for personal agents.
- SaaS memory services lock your data on their servers.

**What's missing**: a lightweight, self-hosted, graph + vector, MCP-native memory layer.

## Core Design Insight: A Shared Vocabulary Between Humans and AI

This is what fundamentally separates Sandra from every other graph database.

### The problem with classical graph databases

In Neo4j, a developer writes:

```cypher
CREATE (s:Person)-[:WORKS_AT]->(e:Company)
```

The label `:WORKS_AT` is a **free-form string** chosen by a developer. It exists only in the context of that query. There is no global registry of what "WORKS_AT" means. Another developer could create `:EMPLOYED_BY` for the same relationship. The database doesn't know they are the same concept.

**The schema is implicit.** It lives in the developer's head, not in the database.

### Sandra's fundamental difference

In Sandra, every concept — every verb, every label, every category — is a **first-class citizen with a unique ID and a human-readable shortname**:

```
works_at  = concept ID 94  (exists once, globally)
likes     = concept ID 140 (exists once, globally)
chocolate = concept ID 141 (exists once, globally)
```

When an AI agent creates a triplet `Shaban → likes → chocolate`, it doesn't write a string. It **references a concept in a shared vocabulary** that any intelligence — human or artificial — can read and understand.

### Why this matters for AI agents

| | Classical Graph DB | Sandra |
|--|-------------------|--------|
| **Relations are** | Free-form strings | Concepts with unique IDs |
| **Vocabulary is** | Implicit (in the code) | Explicit (in the database) |
| **"likes" exists as** | A string when someone writes it | A permanent entity with ID 140 |
| **Who understands the schema** | The developer | Any LLM that can read |
| **Two different agents** | Create incompatible schemas | Share the same vocabulary |

An LLM cannot read a Neo4j schema. It doesn't know which relations exist, which labels are used, what the naming conventions are.

In Sandra, the LLM does:

```
1. sandra_list_concepts → sees the full vocabulary
   likes, works_at, funded_by, completed_training...

2. Understands immediately what each concept means
   because shortnames are natural language

3. Reasons: "user asks about preferences,
   I see a concept 'likes', I traverse"

4. Can CREATE new concepts in the same vocabulary
   "dislikes" → new concept, same pattern
```

**The vocabulary is self-describing.** The AI needs no documentation, no schema file, no README. It reads the concepts and understands.

### The recall mechanism

This is how recall actually works, step by step:

```
User: "What fruit do I like?"

AI reasoning:
  1. This is about preferences → concept "likes" might exist
  2. sandra_list_concepts("%likes%") → found: likes (ID 140)
  3. sandra_get_triplets(Shaban, verb=likes) → targets: strawberry, chocolate
  4. Answer: "You like strawberries and chocolate"
```

The graph traversal follows **named, meaningful relationships**. The AI doesn't search text — it navigates a web of concepts that it can read like a human would read an org chart.

### Three layers of concept retrieval

| Layer | Mechanism | Reliability | When it helps |
|-------|-----------|-------------|---------------|
| **1. Shortname match** | "likes" → ID 140 | 100% | Direct questions ("what do I like?") |
| **2. Pattern search** | "%fund%" → funded_by | 90% | Related questions ("tell me about funding") |
| **3. Semantic search** | embed("my tastes") → likes, chocolate | ~80% | Reformulated questions ("my preferences") |

Each layer catches what the previous one misses. The graph alone (layers 1+2) handles ~70% of queries correctly. Embeddings (layer 3) are a safety net for the remaining 30%.

### Implication for naming

The **single most important act** in Sandra is naming a concept well:

```
Good:   "likes", "works_at", "funded_by"     → AI finds them naturally
Bad:    "rel_type_7", "usr_pref", "fn_01"    → invisible to AI
```

Sandra works with LLMs out of the box because its vocabulary is written in the same language the LLM thinks in.

### The deeper truth

Classical databases are **contracts between developers**. You define tables, columns, types, and developers code around them.

Sandra's vocabulary is a **contract between intelligences** — human or artificial. Any entity capable of understanding natural language can read, write, and reason over the graph.

```
Classical graph DB:
    Developer → defines schema → codes app → end user
    (schema is a technical artifact)

Sandra:
    Intelligence (human or AI) → reads concepts → understands → acts
    (vocabulary is a semantic artifact)
```

Sandra is not "another graph database". It is a **living ontology** designed to be read and written by intelligences, not by code.

**Positioning**: "A shared vocabulary between humans and AI agents — where every concept is named in natural language, has a unique identity, and can be reasoned about."

## The Solution: Three Layers

```
Layer 1: GRAPH
    Entities, concepts, triplets
    Exact relationships: "Shaban works_at EverdreamSoft"
    Traversable: "Who works at EverdreamSoft?" -> complete answer

Layer 2: VECTOR
    Embeddings on every entity
    Semantic search: "funding history" -> finds FIT, Venture Kick
    Auto-embedded on create/update

Layer 3: AGENT
    Auto-recall before responding
    Auto-save after conversations
    Consolidation of stale/duplicate data
    The layer that makes it all invisible to the user
```

## Architecture

```
                    +------------------+
                    |    User / LLM    |
                    +--------+---------+
                             |
                    +--------v---------+
                    |  Agent Middleware |
                    |  (Layer 3)       |
                    |                  |
                    |  - Detect intent |
                    |  - Auto-recall   |
                    |  - Auto-save     |
                    |  - Consolidate   |
                    +----+--------+----+
                         |        |
              +----------+        +----------+
              |                              |
    +---------v----------+      +-----------v---------+
    |   Graph Engine      |      |  Vector Engine      |
    |   (Layer 1)         |      |  (Layer 2)          |
    |                     |      |                     |
    |   - Entities        |      |  - Embeddings       |
    |   - Triplets        |      |  - Cosine similarity|
    |   - Exact relations |      |  - Semantic search  |
    +----------+----------+      +-----------+---------+
               |                             |
               +-------------+---------------+
                             |
                    +--------v---------+
                    |     MySQL        |
                    +------------------+
```

## Technical Decisions

### Why MySQL (or SQLite) — not a dedicated vector DB?
- Runs on the database you already have: MySQL/MariaDB in production, SQLite for lightweight or embedded setups.
- MySQL fits naturally with complex web systems — no new service to run alongside your app.
- JSON column for vectors works fine up to ~100k entities.
- Cosine similarity in PHP is fast enough at this scale.
- Migration path to pgvector or a dedicated vector store stays open if your workload outgrows it.

### Why OpenAI embeddings (not local)?
- `text-embedding-3-small` is $0.02/1M tokens — effectively free
- No GPU required on the server
- Can be swapped for any embedding provider (Voyage, Cohere, local)
- API key optional — everything works without it

### Why MCP as the first-class interface for agents?
- MCP is the emerging standard for LLM tool use.
- Direct integration with Claude and a growing ecosystem.
- Low friction locally; token-based auth when the server needs to be reachable.

Sandra also ships with a **REST API** (see [`api-guide.md`](api-guide.md)). Having both surfaces matters in practice: an operator can use an LLM through MCP to reason about a site while the same data is served and modified through REST on the back end. One vocabulary, two surfaces — that's what makes it practical to let an LLM actually operate a real application, not just answer questions about it.

## Appendix: Cost Model

| Operation | Cost | At 1000/day |
|-----------|------|-------------|
| Embed entity (create/update) | $0.00002 | $0.02/day |
| Semantic search | $0.00002 | $0.02/day |
| Auto-recall (Haiku pre-pass) | $0.001 | $1.00/day |
| Auto-save (Haiku post-pass) | $0.001 | $1.00/day |
| **Total** | | **~$2/day at heavy usage** |

For a personal assistant doing 50-100 interactions/day: **~$0.10-0.20/day**.
