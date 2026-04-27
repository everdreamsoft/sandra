<p align="center">
    <img src="resources/images/SandraBanner.png" alt="Sandra">
</p>

<p align="center">
    <strong>Long-term memory for AI agents.</strong><br>
    Self-hosted, MCP-native, with a vocabulary your LLM can read.
</p>

<p align="center">
    Sandra + a query planner scores <strong>0.89</strong> on <a href="https://github.com/everdreamsoft/structured-recall-bench">Structured Recall Bench</a>,
    where top-K retrievers cluster at 0.25 to 0.48.
</p>

---

Every AI agent forgets. Vector memory finds similar text but can't enumerate or relate. Classical graph DBs use free-form labels that no LLM can read. SaaS memory ships your data to someone else's servers.

Sandra is a self-hostable graph + vector memory layer where every concept (verb, label, category) has a unique ID and a human-readable name. Your agent reads, writes, and reasons over a shared vocabulary instead of an implicit schema. Exposed natively via MCP.

## Give your agent memory

```bash
# 1. Run Sandra locally (5 min, see installation guide)
git clone https://github.com/everdreamsoft/sandra
cd sandra && composer install
# ...configure DB and (optional) OpenAI key, then:
php bin/mcp-http-server.php

# 2. Connect Claude Code
claude mcp add sandra --transport http --url http://127.0.0.1:8090/mcp
```

Open Claude. Memory now persists across sessions, projects, and any other MCP-aware agent on the same Sandra instance.

Full setup, OAuth 2.1/PKCE, and auth tokens: [`docs/installation-guide.md`](docs/installation-guide.md). MCP tool reference: [`docs/mcp-guide.md`](docs/mcp-guide.md). A ready-made Claude Code agent configuration: [`examples/claude-code-agent/`](examples/claude-code-agent/).

## Why Sandra

| Approach              | Example                | SRB composite | Limit                                       |
|-----------------------|------------------------|---------------|---------------------------------------------|
| Vector retrieval      | Mem0, Zep, Supermemory | 0.25 to 0.33  | Can't enumerate, can't relate               |
| Verbatim retrieval    | MemPalace              | 0.48          | Same architecture, better organized         |
| Classical graph DB    | Neo4j, TigerGraph      | (no provider) | Free-form labels, unreadable for LLMs       |
| **Sandra + planner**  | this repo              | **0.89**      | Graph + vector + shared LLM-readable vocab  |

Numbers from [Structured Recall Bench](https://github.com/everdreamsoft/structured-recall-bench): 130 deterministic questions, no LLM-judge, raw responses archived. Full design rationale: [`docs/agent-memory-design.md`](docs/agent-memory-design.md).

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

Four primitives. Everything else is built from them.

- **Concept**: a named, unique ID in a shared vocabulary (`likes`, `works_at`, `strawberry`). The *words* of the graph.
- **Entity**: a typed record with short reference fields, plus optional long text. The *rows*.
- **Factory**: a typed collection of entities (`person`, `product`, `task`). The *tables*.
- **Triplet**: a `(subject, verb, target)` link. The *sentences*.

```
Entity(Alice) ── likes ────▶ Concept(strawberry)
     │
     └── works_with ──▶ Entity(Bob)
```

Because `likes` and `works_with` are themselves concepts with stable IDs, an LLM can call `sandra_list_concepts` and read the entire vocabulary. No schema file, no documentation needed. That is what makes the graph natively LLM-readable.

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
```

Longer walkthrough: [`docs/code-samples/animal-shelter.php`](docs/code-samples/animal-shelter.php).

## Documentation

| Guide | What it covers |
|---|---|
| [`installation-guide.md`](docs/installation-guide.md) | Set up Sandra + MCP server + Claude Code skill |
| [`mcp-guide.md`](docs/mcp-guide.md) | MCP server reference: tools, configuration, custom tools |
| [`api-guide.md`](docs/api-guide.md) | REST API and PHP SDK: wire Sandra into Slim, Laravel, or vanilla PHP |
| [`agent-memory-design.md`](docs/agent-memory-design.md) | Why Sandra is shaped the way it is, the design rationale |
| [`concept-deduplication.md`](docs/concept-deduplication.md) | How the vocabulary stays clean at scale |
| [`system-concept-scaling.md`](docs/system-concept-scaling.md) | Memory and performance profile as concept count grows |

## Status

Used in production at [EverdreamSoft](https://everdreamsoft.com), notably in [Spells of Genesis](https://spellsofgenesis.com). The MCP layer and OAuth 2.1/PKCE auth are active.

## License

MIT, see [`LICENSE`](LICENSE).

## Contributing

Pull requests welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for conventions, tests, and the branch policy.
