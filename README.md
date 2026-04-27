<p align="center">
    <img src="resources/images/SandraBanner.png" alt="Sandra: synapses for AI agents">
</p>

<p align="center">
    <strong>Synapses for AI agents.</strong>
</p>

<p align="center">
    Scores <strong>0.89</strong> on
    <a href="https://github.com/everdreamsoft/structured-recall-bench">Structured Recall Bench</a>,
    where vector stores cluster between 0.25 and 0.48.
    Self-hosted on your hardware, accessible to any MCP-aware client:
    Claude, GPT, Gemini, Llama, Mistral, or your own.
</p>

<p align="center">
    <a href="https://github.com/everdreamsoft/structured-recall-bench">
        <img src="https://raw.githubusercontent.com/everdreamsoft/structured-recall-bench/main/results/scoreboard.svg"
             alt="Structured Recall Bench scoreboard: Sandra 0.89, vector and verbatim retrievers between 0.25 and 0.48">
    </a>
</p>

---

Every AI agent forgets. Vector memory finds similar text but can't enumerate or relate. Classical graph DBs use free-form labels that no LLM can read. SaaS memory ships your data to someone else's servers.

Sandra is a self-hostable graph + vector memory layer where every concept (verb, label, category) has a unique ID and a human-readable name. Your agents read, write, and reason over a *shared lexicon*. Every relationship is an explicit synapse you can trace. Exposed natively via MCP, so any LLM that can call a tool can use it.

## Give your agent memory

```bash
# 1. Run Sandra locally with Docker (1 min)
git clone https://github.com/everdreamsoft/sandra && cd sandra
cp .env.example .env   # optional: set OPENAI_API_KEY for semantic search
docker compose up -d   # Sandra MCP on http://127.0.0.1:8090/mcp

#    Or from source: see docs/installation-guide.md (PHP 8+, MySQL, composer)

# 2. Connect any MCP-aware client
# Claude Code:
claude mcp add sandra --transport http --url http://127.0.0.1:8090/mcp
# Cursor, Cline, Continue, Zed, OpenAI Agents SDK, custom clients:
# point your client's MCP server config at http://127.0.0.1:8090/mcp

# 3. (Optional) Connect claude.ai web (needs HTTPS)
# /!\ Set SANDRA_AUTH_TOKEN in .env first: the trycloudflare URL is public.
docker compose --profile tunnel up -d
docker compose logs tunnel | grep trycloudflare
# → paste the https://...trycloudflare.com/mcp URL into claude.ai
```

Once connected, your agent gains persistent memory across sessions, projects, machines, and clients, regardless of the underlying model (Claude, GPT, Gemini, Llama, Mistral, ...).

Building an app instead of an agent setup? Skip to [Building on Sandra](#building-on-sandra) for the PHP SDK and REST API.

### What recall feels like

```text
─ Monday, ChatGPT on the web ─
You:    CSV of 100 tweets I wrote this year (text, likes, replies).
        Save them in Sandra and tag each with its emotional register.
Agent:  [sandra_batch → 100 tweet entities; refs: text, engagement,
                       emotion (awe / pride / frustration / joy / ...)]

─ Wednesday, Claude Code on your laptop ─
You:    Design a landing page that resonates with the emotions my
        audience reacts to most.
Agent:  [sandra_search → emotion breakdown, weighted by engagement]
        Your audience peaks on awe and pride. Drafting a hero in that
        register, plus a social-proof block citing your three
        highest-engagement tweets...
```

The agent recalls because the memory lives in *your* Sandra instance, not in any model's context window. Switch models, switch clients, switch machines: the memory stays.

Full setup, OAuth 2.1/PKCE, auth tokens: [`docs/installation-guide.md`](docs/installation-guide.md). MCP tool reference: [`docs/mcp-guide.md`](docs/mcp-guide.md). Example agent config (Claude Code): [`examples/claude-code-agent/`](examples/claude-code-agent/).

## Why Sandra

| Approach              | Example                | SRB composite | Trade-off                                     |
|-----------------------|------------------------|---------------|-----------------------------------------------|
| Vector retrieval      | Mem0, Zep, Supermemory | 0.25 to 0.33  | Finds similar text, can't enumerate or relate |
| Verbatim retrieval    | MemPalace              | 0.48          | A static memory palace, no associative recall |
| Classical graph DB    | Neo4j, TigerGraph      | (no provider) | Free-form labels, unreadable for LLMs         |
| **Sandra + planner**  | this repo              | **0.89**      | Graph + vector + shared LLM-readable lexicon  |

Numbers from [Structured Recall Bench](https://github.com/everdreamsoft/structured-recall-bench): 130 deterministic questions, no LLM-judge, raw responses archived. Full design rationale: [`docs/agent-memory-design.md`](docs/agent-memory-design.md).

## Beyond agent memory

Agents are a natural consequence of Sandra, not the reason Sandra exists. Memory is one mode. The same typed knowledge graph behind an LLM interface also handles:

- **Personal knowledge management.** Stash articles, strategy notes, ideas as typed entities. Ask: "Give me the 10 most-engaging emotional tweets I posted; suggest an eleventh."
- **Relationship CRM.** Track who covered you, who knows whom, who said what. "Which journalists wrote about us this year? Who at TechCrunch?"
- **Headless CMS for LLM-driven apps.** Expose Sandra over the REST API + a thin UI; let agents update content from natural language. "Change the price of the Geneva listing to 350."
- **Lightweight analytics.** Log who-viewed-what as triplets, query later via the same MCP tools. "Which cards got the most attention from European visitors last week?"

Same primitives (concept, triplet, entity, factory), same lexicon, same MCP and REST surface. Switching frame is a query, not a migration.

## What Sandra is, and is not

**Sandra is for you if:**
- You want your agent to remember across sessions, projects, and machines
- You want to own the memory data, not lease it from a SaaS
- You build with multiple LLMs and need a shared substrate
- You need explicit relationships, not just fuzzy similarity

**Sandra is not for you if:**
- You need a transactional database (use Postgres)
- You need pure vector retrieval at massive scale (use a dedicated vector store)
- You want zero infrastructure (use Mem0 or another hosted service)

## How memory works

Sandra works like the associative cortex your agent never had. Four primitives, mapped to what neurons do.

- **Concept**: a stable, named ID; the *neuron* of the shared network (`likes`, `works_at`, `user`).
- **Triplet**: a `(subject, verb, target)` link; the *synapse* between two neurons.
- **Entity**: a typed cluster of refs grouped by a factory; a *named region* of the network.
- **Factory**: the schema for an entity cluster (`person`, `product`, `task`).

DB analog if you prefer: concepts are *words*, entities are *rows*, factories are *tables*, triplets are *sentences*.

```
Entity(Alice) ── likes ───────▶ Concept(strawberry)
     │
     └── works_with ──────────▶ Entity(Bob)
```

When your agent encounters `Alice`, Sandra activates that node and follows every synapse outward (e.g. `Alice → likes → strawberry`, `Alice → works_with → Bob`) in a single query. Encounter `Bob` later and the same network lights up from a different angle. This is *spreading activation* (Collins & Loftus, 1975), the mechanism behind associative human recall. Vector retrievers approximate it via embedding similarity; Sandra implements it directly via typed graph edges.

Because every concept is itself a stable, named ID, an LLM can call `sandra_list_concepts` and read the entire vocabulary. No schema file, no documentation needed. That is what makes the graph natively LLM-readable.

### One concept, many implementations

A concept's identity is stable, but the attributes attached to it can vary by source. Your agent talks to ten systems, each one calling the same human a different name and tracking different attributes. Sandra holds *one* concept and lets every system attach its own view:

```
Concept(user)
  ─ in_system → marketing_db    refs: { name: "User",      ltv: 4200, segment: "enterprise" }
  ─ in_system → auth_service    refs: { name: "Principal", email: "alice@acme.com", mfa: true }
  ─ in_system → support_crm     refs: { name: "Customer",  tier: "gold", open_tickets: 2 }
```

One referent, three radically different elaborations. A vector store would collapse them via cosine similarity and lose the structure; a classical graph DB would let you connect them but couldn't show the LLM the vocabulary. Sandra preserves the identity (`Concept(user)` has one ID across all triplets), lets each source attach its own refs, and exposes the whole lexicon to any MCP-aware agent.

This is what philosophers since Frege have called *invariance of reference under variation of sense*. What programmers call polymorphism. What your agents need so they can answer questions like "show me everything we know about this user" without lying, conflating, or losing source attribution.

### Building a team of agents?

Because every agent is itself an entity in the same graph it reads from, multi-agent systems on Sandra are *reflective*: agents discover, read, audit, and update each other's missions using the same MCP tools they use for memory. Pattern, examples, and a comparison with CrewAI / LangGraph / OpenAI Assistants in [`docs/multi-agent.md`](docs/multi-agent.md).

## Building on Sandra

Sandra is also a full data backend. If you build an app on top of it:

- **PHP SDK**: `composer require everdreamsoft/sandra` ([api-guide.md](docs/api-guide.md))
- **REST API**: language-agnostic ([api-guide.md](docs/api-guide.md))
- **Python and TypeScript SDKs**: coming

```php
<?php
use SandraCore\System;
use SandraCore\EntityFactory;

$sandra = new System('myapp', true, 'localhost', 'sandra', 'root', '');
$people = new EntityFactory('person', 'peopleFile', $sandra);

$alice = $people->createNew(['name' => 'Alice', 'role' => 'founder']);
$bob   = $people->createNew(['name' => 'Bob',   'role' => 'engineer']);
$alice->setJoinedEntity('works_with', $bob, ['since' => '2024']);

$people->populateLocal();
foreach ($people->getEntities() as $p) {
    echo $p->get('name') . "\n";
}
```

Longer walkthrough: [`docs/code-samples/animal-shelter.php`](docs/code-samples/animal-shelter.php).

## Documentation

| Guide | What it covers |
|---|---|
| [`installation-guide.md`](docs/installation-guide.md) | Set up Sandra + MCP server + your MCP client (Claude Code example included) |
| [`mcp-guide.md`](docs/mcp-guide.md) | MCP server reference: tools, configuration, custom tools |
| [`api-guide.md`](docs/api-guide.md) | REST API and PHP SDK: wire Sandra into Slim, Laravel, or vanilla PHP |
| [`agent-memory-design.md`](docs/agent-memory-design.md) | Why Sandra is shaped the way it is, the design rationale |
| [`concept-deduplication.md`](docs/concept-deduplication.md) | How the vocabulary stays clean at scale |
| [`system-concept-scaling.md`](docs/system-concept-scaling.md) | Memory and performance profile as concept count grows |

## Status

Used in production at [EverdreamSoft](https://everdreamsoft.com), notably in [Spells of Genesis](https://spellsofgenesis.com). The MCP layer and OAuth 2.1/PKCE auth are running in production.

## License

MIT, see [`LICENSE`](LICENSE).

## Contributing

Pull requests welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for conventions, tests, and the branch policy.
