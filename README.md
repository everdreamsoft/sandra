<p align="center">
    <img src="resources/images/SandraBanner.png" alt="Sandra: synapses for AI agents">
</p>

<p align="center">
    <strong>Synapses for AI agents.</strong><br>
    Self-hostable graph + vector memory. Native MCP. <strong>0.89</strong> on
    <a href="https://github.com/everdreamsoft/structured-recall-bench">Structured Recall Bench</a>,
    where vector stores cluster between 0.25 and 0.48.
</p>

<p align="center">
    <a href="#install"><img src="https://img.shields.io/badge/Install-1--min-2ea44f?style=for-the-badge" alt="Install"></a>
    <a href="https://sandra.everdreamsoft.com"><img src="https://img.shields.io/badge/Quickstart-sandra.everdreamsoft.com-0a66c2?style=for-the-badge" alt="Quickstart"></a>
    <a href="https://discord.gg/9ptCvXJzy"><img src="https://img.shields.io/badge/Discord-join-5865F2?style=for-the-badge&logo=discord&logoColor=white" alt="Discord"></a>
</p>

<p align="center">
    <a href="https://github.com/everdreamsoft/structured-recall-bench">
        <img src="https://raw.githubusercontent.com/everdreamsoft/structured-recall-bench/main/results/scoreboard.svg"
             alt="Structured Recall Bench scoreboard: Sandra 0.89, vector and verbatim retrievers between 0.25 and 0.48">
    </a>
</p>

---

Sandra is a self-hostable graph + vector memory layer where every concept (verb, label, category) has a unique ID and a human-readable name. Your agents read, write, and reason over a *shared lexicon*. Every relationship is an explicit synapse you can trace. Exposed natively via MCP, so any LLM that can call a tool can use it.

## Install

```bash
git clone https://github.com/everdreamsoft/sandra && cd sandra
docker compose up -d                                   # MCP on http://127.0.0.1:8090/mcp
claude mcp add sandra --transport http --url http://127.0.0.1:8090/mcp
```

That's it — your agent now has persistent memory across sessions, projects, machines, and clients (Claude, GPT, Gemini, Llama, Mistral...).

<details>
<summary>Other clients · semantic search · claude.ai web (HTTPS) · from source</summary>

```bash
cp .env.example .env                          # set OPENAI_API_KEY for semantic search
docker compose --profile tunnel up -d         # public HTTPS via trycloudflare for claude.ai
docker compose logs tunnel | grep trycloudflare
```

Cursor, Cline, Continue, Zed, OpenAI Agents SDK, custom clients: point your client's MCP server config at `http://127.0.0.1:8090/mcp`. From source (PHP 8+, MySQL, composer): see [`docs/installation-guide.md`](docs/installation-guide.md). Set `SANDRA_AUTH_TOKEN` in `.env` before exposing the trycloudflare URL — it is public.

</details>

Full setup, OAuth 2.1/PKCE, auth tokens: [`docs/installation-guide.md`](docs/installation-guide.md). MCP tool reference: [`docs/mcp-guide.md`](docs/mcp-guide.md). Live quickstart and demos: [sandra.everdreamsoft.com](https://sandra.everdreamsoft.com). Questions? [Join the Discord](https://discord.gg/9ptCvXJzy).

## What recall feels like

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

Memory lives in *your* Sandra instance, not in any model's context window. Switch models, switch clients, switch machines: the memory stays.

## Why Sandra

| Approach              | Example                | SRB composite | Trade-off                                     |
|-----------------------|------------------------|---------------|-----------------------------------------------|
| Vector retrieval      | Mem0, Zep, Supermemory | 0.25 to 0.33  | Finds similar text, can't enumerate or relate |
| Verbatim retrieval    | MemPalace              | 0.48          | A static memory palace, no associative recall |
| Classical graph DB    | Neo4j, TigerGraph      | (no provider) | Free-form labels, unreadable for LLMs         |
| **Sandra + planner**  | this repo              | **0.89**      | Graph + vector + shared LLM-readable lexicon  |

130 deterministic questions, no LLM-judge, raw responses archived. Full design rationale: [`docs/agent-memory-design.md`](docs/agent-memory-design.md).

**Sandra is for you if** you want your agent to remember across sessions and machines, you want to own the data, you build with multiple LLMs, or you need explicit relationships — not fuzzy similarity. **Skip Sandra if** you need a transactional database (use Postgres), pure vector retrieval at massive scale (use a dedicated vector store), or zero infrastructure (use a hosted service).

## How memory works

Four primitives, mapped to what neurons do.

- **Concept** — a stable, named ID; the *neuron* (`likes`, `works_at`, `user`).
- **Triplet** — a `(subject, verb, target)` link; the *synapse*.
- **Entity** — a typed cluster of refs grouped by a factory; a *named region*.
- **Factory** — the schema for an entity cluster (`person`, `product`, `task`).

DB analog: concepts are *words*, entities are *rows*, factories are *tables*, triplets are *sentences*.

```
Entity(Alice) ── likes ───────▶ Concept(strawberry)
     │
     └── works_with ──────────▶ Entity(Bob)
```

Encounter `Alice`, Sandra activates that node and follows every synapse outward in one query — *spreading activation* ([Collins & Loftus, 1975](https://doi.org/10.1037/0033-295X.82.6.407)), the mechanism behind associative human recall. Because every concept is a stable, named ID, an LLM can call `sandra_list_concepts` and read the entire vocabulary. No schema file, no documentation needed.

### One concept, many implementations

A concept's identity is stable, but the attributes attached to it can vary by source. `Concept(user)` keeps a single ID across every system that talks about it:

```
Concept(user)
  ─ in_system → marketing_db    refs: { name: "User",      ltv: 4200, segment: "enterprise" }
  ─ in_system → auth_service    refs: { name: "Principal", email: "alice@acme.com", mfa: true }
  ─ in_system → support_crm     refs: { name: "Customer",  tier: "gold", open_tickets: 2 }
```

This is what philosophers [since Frege](https://en.wikipedia.org/wiki/Sense_and_reference) have called *invariance of reference under variation of sense*. What programmers call polymorphism. What your agents need so they can answer "show me everything we know about this user" without lying, conflating, or losing source attribution.

Building a multi-agent system? Every agent is itself an entity in the same graph it reads from. Pattern + comparison with CrewAI / LangGraph / OpenAI Assistants in [`docs/multi-agent.md`](docs/multi-agent.md).

## Beyond agent memory

Same primitives also handle:

- **Personal knowledge management** — *"Give me my 10 most-engaging emotional tweets; suggest an eleventh."*
- **Relationship CRM** — *"Which journalists wrote about us this year? Who at TechCrunch?"*
- **Headless CMS for LLM-driven apps** — *"Change the price of the Geneva listing to 350."*
- **Lightweight analytics** — *"Which cards got the most attention from European visitors last week?"*

Switching frame is a query, not a migration.

## Building on Sandra

- **PHP SDK** — `composer require everdreamsoft/sandra` ([api-guide.md](docs/api-guide.md))
- **REST API** — language-agnostic ([api-guide.md](docs/api-guide.md))
- **Python and TypeScript SDKs** — coming

```php
<?php
use SandraCore\System;
use SandraCore\EntityFactory;

$sandra = new System('myapp', true, 'localhost', 'sandra', 'root', '');
$people = new EntityFactory('person', 'peopleFile', $sandra);

$alice = $people->createNew(['name' => 'Alice', 'role' => 'founder']);
$bob   = $people->createNew(['name' => 'Bob',   'role' => 'engineer']);
$alice->setJoinedEntity('works_with', $bob, ['since' => '2024']);
```

Longer walkthrough: [`docs/code-samples/animal-shelter.php`](docs/code-samples/animal-shelter.php).

## Documentation

| Guide | What it covers |
|---|---|
| [`installation-guide.md`](docs/installation-guide.md) | Sandra + MCP server + your MCP client |
| [`mcp-guide.md`](docs/mcp-guide.md) | MCP server reference: tools, configuration, custom tools |
| [`api-guide.md`](docs/api-guide.md) | REST API and PHP SDK |
| [`agent-memory-design.md`](docs/agent-memory-design.md) | Why Sandra is shaped the way it is |
| [`concept-deduplication.md`](docs/concept-deduplication.md) | How the vocabulary stays clean at scale |
| [`system-concept-scaling.md`](docs/system-concept-scaling.md) | Memory and performance profile as concept count grows |

## Community & Status

Join the conversation on [Discord](https://discord.gg/9ptCvXJzy). Live quickstart at [sandra.everdreamsoft.com](https://sandra.everdreamsoft.com).

Used in production at [EverdreamSoft](https://everdreamsoft.com), notably in [Spells of Genesis](https://spellsofgenesis.com). MCP layer and OAuth 2.1/PKCE auth running in production.

MIT license — see [`LICENSE`](LICENSE). Pull requests welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md).
