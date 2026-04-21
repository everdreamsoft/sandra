# Sandra Agent Memory

## Vision

Transform Sandra from a graph database into the **open-source standard for AI agent memory** — a self-hosted, graph + vector, MCP-native memory layer that gives any LLM persistent, relational, semantic memory.

No AI agent today has a satisfying long-term memory. Sandra solves this.

## The Problem

Every AI agent suffers from amnesia:
- Conversations reset. Context is lost.
- Text-based memory (LangChain, etc.) stores blobs with no structure.
- Vector-only databases (Pinecone, Chroma) find similar text but don't understand relationships.
- Enterprise graph databases (Neo4j) are too heavy for personal agents.
- SaaS memory services (Mem0, Zep) lock your data on their servers.

**Nobody has shipped**: a lightweight, self-hosted, graph + vector, MCP-native memory layer for AI agents.

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

## The Solution: Sandra Memory Agent

Three layers working together:

```
Layer 1: GRAPH (exists today)
    Entities, concepts, triplets
    Exact relationships: "Shaban works_at EverdreamSoft"
    Traversable: "Who works at EverdreamSoft?" -> complete answer

Layer 2: VECTOR (implemented - pending deployment)
    Embeddings on every entity
    Semantic search: "funding history" -> finds FIT, Venture Kick
    Auto-embedded on create/update

Layer 3: AGENT (next phase)
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

## Implementation Phases

### Phase 1: Graph + Vector (DONE)

**Status: Implemented**

- Sandra graph database with MCP server (18 tools)
- Embedding table in MySQL (JSON vectors)
- `EmbeddingService` — OpenAI API, cosine similarity, hash-based skip
- `sandra_semantic_search` — new MCP tool for natural language search
- Auto-embed on entity create/update/batch
- Graceful degradation without API key

**Cost**: ~$0.02 per 1M tokens embedded. Effectively free.

### Phase 2: Agent Middleware

**Status: Next**

The middleware intercepts every conversation turn and orchestrates recall + save.

#### 2a. Auto-Recall (before LLM responds)

When a user message comes in:

1. **Entity detection** — extract names, companies, topics from the message
   - Use a fast LLM call (Haiku) or regex for known patterns
   - Cost: ~$0.001 per message

2. **Semantic search** — embed the message, find relevant entities
   - `sandra_semantic_search(message)` -> top 5-10 relevant entities
   - Cost: ~$0.00002 per search (one embedding call)

3. **Graph traversal** — for each found entity, pull related entities
   - `sandra_get_triplets(entityId)` -> relationships
   - Load connected entities for context

4. **Context injection** — assemble a brief and inject into the LLM prompt
   - "Here's what you know about the entities mentioned: ..."
   - Keep it compact: max ~500 tokens of context

**Implementation options:**
- **MCP tool**: `sandra_recall(message)` — the LLM calls it explicitly
- **Hook/middleware**: automatically runs before every LLM response (transparent)
- **Hybrid**: MCP tool + system prompt instruction to always call it first

#### 2b. Auto-Save (after LLM responds)

After the conversation turn:

1. **Extract new information** from the exchange
   - New people mentioned? New facts? Preferences? Tasks?
   - Use a structured LLM call: "What new facts were learned?"

2. **Decide what to store**
   - New entity? Update existing? New relationship?
   - Conservative: better to miss info than pollute the graph

3. **Execute** via Sandra MCP tools
   - `sandra_create_entity`, `sandra_update_entity`, `sandra_batch`
   - Embedding happens automatically

**Rules:**
- Only save non-trivial, reusable information
- Don't save ephemeral conversation details
- Don't save information already in the graph (check first)
- Always prefer updating existing entities over creating duplicates

#### 2c. Consolidation (periodic background task)

Runs on a schedule (daily or weekly):

1. **Duplicate detection** — find entities with similar embeddings
   - "Universit de Geneve" vs "UNIGE" vs "Uni Geneva"
   - Propose merges

2. **Stale data detection** — find old tasks, outdated info
   - Tasks older than 30 days still "pending"
   - Contact info that hasn't been verified

3. **Relationship inference** — suggest new connections
   - Two people who share the same company but aren't linked
   - Entities that belong to the same semantic cluster

4. **Cleanup** — remove orphaned concepts, empty entities

### Phase 3: Multi-Agent & API

**Status: Future**

- REST API for external apps to read/write Sandra memory
- Multiple agents sharing the same memory (team knowledge base)
- Memory namespaces (personal vs team vs project)
- Access control and privacy layers
- Export/import for portability

## Competitive Landscape

| Solution | Graph | Vector | Self-hosted | MCP | Agent layer |
|----------|-------|--------|-------------|-----|-------------|
| **Sandra (target)** | Yes | Yes | Yes | Yes | Yes |
| Mem0 | No | Yes | No (SaaS) | No | Partial |
| Zep | No | Yes | No (SaaS) | No | Yes |
| LangChain Memory | No | Partial | Yes | No | No |
| Neo4j | Yes | Plugin | Yes | No | No |
| ChromaDB | No | Yes | Yes | No | No |

**Sandra's unique position**: the only solution combining all five columns.

## Go-to-Market

### Target Audience
AI developers building agents with Claude, GPT, or local LLMs who need persistent memory.

### Positioning
**Primary**: "A shared vocabulary between humans and AI — persistent memory where every concept has a name and an identity."

**Technical**: "The open-source AI memory layer. Graph + Vector. Self-hosted. MCP-native."

### Validation Strategy (4 weeks)

**Week 1 — Smoke test**
- Tweet/post showing the demo (this conversation = the demo)
- Reddit r/LocalLLaMA, Hacker News "Show HN"
- Measure: engagement, DMs, interest

**Week 2 — Video demo**
- 2-minute video: "Watch this AI remember everything"
- Show: create entities, new conversation, recall, semantic search
- Publish: Twitter, YouTube, Reddit, HN

**Week 3 — Early adopters**
- Target: 10 developers who actually use it
- Channels: Anthropic Discord, LangChain Discord, GitHub Issues of competitors
- Approach: "I have this problem, here's my solution, try it"

**Week 4 — Signal**
- If traction: announce design partner program
- If no traction: pivot positioning or feature set

### Key Channels
- GitHub (primary distribution)
- Twitter/X (AI developer community)
- Hacker News (early adopters)
- Anthropic MCP ecosystem (natural home)
- Reddit r/LocalLLaMA, r/artificial

## Technical Decisions

### Why MySQL (not a dedicated vector DB)?
- Zero additional infrastructure
- Sandra already uses MySQL
- JSON column for vectors works fine up to ~100k entities
- Cosine similarity in PHP is fast enough for this scale
- Can migrate to pgvector/dedicated solution later if needed

### Why OpenAI embeddings (not local)?
- `text-embedding-3-small` is $0.02/1M tokens — effectively free
- No GPU required on the server
- Can be swapped for any embedding provider (Voyage, Cohere, local)
- API key optional — everything works without it

### Why MCP (not REST API)?
- MCP is the emerging standard for LLM tool use
- Direct integration with Claude, and growing ecosystem
- No authentication complexity — runs locally
- Can add REST API later (Phase 3) for external apps

## Success Metrics

### Phase 1 (current)
- Embeddings work end-to-end
- Semantic search returns relevant results
- No performance degradation on normal operations

### Phase 2
- Agent auto-recalls relevant context in >80% of cases
- Auto-save captures >60% of new important information
- Consolidation reduces duplicates by >50%

### Phase 3
- 1,000+ GitHub stars
- 100+ active installations
- 10+ contributors
- First paid enterprise inquiry

## Appendix: Cost Model

| Operation | Cost | At 1000/day |
|-----------|------|-------------|
| Embed entity (create/update) | $0.00002 | $0.02/day |
| Semantic search | $0.00002 | $0.02/day |
| Auto-recall (Haiku pre-pass) | $0.001 | $1.00/day |
| Auto-save (Haiku post-pass) | $0.001 | $1.00/day |
| **Total** | | **~$2/day at heavy usage** |

For a personal assistant doing 50-100 interactions/day: **~$0.10-0.20/day**.
