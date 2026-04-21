<p align="center">
    <img src="resources/images/SandraBanner.png" alt="Sandra">
</p>

<p align="center">
    <strong>A graph database where every concept has a name, a unique identity, and a story.</strong><br>
    Strict typed queries, infinite relational memory, and a vocabulary that any intelligence — human or AI — can read, write, and reason over.
</p>

---

Sandra is a semantic graph database built around a radical idea: every concept — every verb, every category, every relationship — is a **first-class citizen with a unique ID and a human-readable name**. That makes the whole graph readable by code, by humans, and natively by large language models. You get strict typed tables when you want SQL-like precision, and a living ontology when you want relational depth. Agents on top (via MCP) are a natural consequence, not the reason Sandra exists.

## Why Sandra

Existing memory options for AI agents fall into three camps, each with a ceiling:

| Approach | Example | Great at | Ceiling |
|---|---|---|---|
| Vector retrieval | Mem0, Zep, Supermemory | Fuzzy top-K recall on prose | Can't enumerate, can't relate, locks data in SaaS |
| Classical graph DB | Neo4j, TigerGraph | Typed relationships | Schema is implicit strings; LLMs can't read it |
| Plain text memory | LangChain buffers | Simplicity | No structure, no query, no scale |

Sandra combines **typed structure** (like a graph DB), **semantic search** (like a vector DB), **self-hostable** (you own your data), and a **shared vocabulary** that any LLM can browse and reason over without a schema file. See [`docs/agent-memory-design.md`](docs/agent-memory-design.md) for the full rationale.

## Quickstart

```bash
composer require everdreamsoft/sandra
```

Minimal program — create a factory, add entities, link them, query back:

```php
<?php
require 'vendor/autoload.php';

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

For a longer walkthrough, see [`docs/code-samples/animal-shelter.php`](docs/code-samples/animal-shelter.php).

## Sandra as Claude's memory (MCP)

Sandra exposes the graph to any MCP-compatible LLM — Claude Code, Claude Desktop, or your own client — as a set of tools (`sandra_search`, `sandra_get_entity`, `sandra_create_entity`, `sandra_semantic_search`, …).

```bash
# 1. Start the Sandra MCP server
php bin/mcp-http-server.php

# 2. Point Claude Code at it
claude mcp add sandra --transport http --url http://127.0.0.1:8090/mcp
```

From Claude's side, memory is simply recalled and written as part of normal conversation. Full setup, OAuth, and auth tokens in [`docs/installation-guide.md`](docs/installation-guide.md). MCP protocol details in [`docs/mcp-guide.md`](docs/mcp-guide.md). A ready-made agent configuration lives in [`examples/claude-code-agent/`](examples/claude-code-agent/).

## Core model

Sandra has four primitives, and everything else is built from them:

- **Concept** — a named, unique ID in a shared vocabulary (`likes`, `works_at`, `strawberry`). Concepts are the *words* of the graph.
- **Entity** — a typed record with short reference fields (name, email, price…) plus optional long text storage. Entities are the *rows*.
- **Factory** — a typed collection of entities (`person`, `product`, `task`). Factories are the *tables*.
- **Triplet** — a `(subject, verb, target)` link between any two concepts or entities. Triplets are the *sentences*.

```
Entity(Alice) ── likes ────▶ Concept(strawberry)
     │
     └── works_with ──▶ Entity(Bob)
```

The magic is that `likes` and `works_at` are themselves concepts with their own IDs. The vocabulary is explicit, queryable, and self-describing — which is exactly what makes the graph readable by LLMs out of the box.

## Documentation

| Guide | What it covers |
|---|---|
| [`installation-guide.md`](docs/installation-guide.md) | Set up Sandra + MCP server + Claude Code skill |
| [`api-guide.md`](docs/api-guide.md) | REST API layer — wire Sandra into Slim, Laravel, or vanilla PHP |
| [`mcp-guide.md`](docs/mcp-guide.md) | MCP server reference — tools, configuration, custom tools |
| [`agent-memory-design.md`](docs/agent-memory-design.md) | Why Sandra is shaped the way it is — the design rationale |
| [`concept-deduplication.md`](docs/concept-deduplication.md) | How the vocabulary stays clean at scale |
| [`system-concept-scaling.md`](docs/system-concept-scaling.md) | Memory and performance profile as concept count grows |

Examples live in [`examples/`](examples/): a Claude Code agent setup and a browser-based MCP test harness.

## Status

Used in production at [EverdreamSoft](https://everdreamsoft.com). The MCP layer and OAuth 2.1/PKCE auth are active.

## License

MIT — see [`LICENSE`](LICENSE).

## Contributing

Pull requests welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for conventions, tests, and the branch policy.
