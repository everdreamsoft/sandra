# Sandra MCP Server — Usage Guide

The MCP (Model Context Protocol) server exposes Sandra graph operations as **tools** usable by LLM agents (Claude, GPT, etc.).

Zero external dependencies. Transport is JSON-RPC 2.0 over STDIO (stdin/stdout), one JSON line per message.

---

## 1. Quick Start

### Prerequisites

- PHP >= 8.0
- A configured Sandra database (MySQL/SQLite)
- Composer (`vendor/autoload.php` present)

### Verify

```bash
php bin/sandra-mcp
# Server starts and waits on stdin. Ctrl+C to quit.
```

---

## 2. Configuration

### 2a. Claude Code (CLI)

**Option 1 — `.mcp.json` file** at the project root (recommended, shareable with team):

```json
{
    "mcpServers": {
        "sandra-mcp": {
            "type": "stdio",
            "command": "php",
            "args": ["bin/sandra-mcp"],
            "env": {
                "SANDRA_ENV": "myapp",
                "SANDRA_DB_HOST": "localhost",
                "SANDRA_DB_NAME": "mydb",
                "SANDRA_DB_USER": "root",
                "SANDRA_DB_PASS": ""
            }
        }
    }
}
```

**Option 2 — CLI command**:

```bash
claude mcp add --transport stdio --scope project \
  --env SANDRA_ENV=myapp \
  --env SANDRA_DB_HOST=localhost \
  --env SANDRA_DB_NAME=mydb \
  --env SANDRA_DB_USER=root \
  --env SANDRA_DB_PASS= \
  sandra-mcp -- php bin/sandra-mcp
```

Verify:

```bash
claude mcp list           # list configured servers
claude mcp get sandra-mcp # details of a server
```

Inside Claude Code, type `/mcp` to see connected server status.

### 2b. Claude Desktop (GUI app)

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
    "mcpServers": {
        "sandra": {
            "command": "php",
            "args": ["/absolute/path/to/sandra/bin/sandra-mcp"],
            "env": {
                "SANDRA_ENV": "myapp",
                "SANDRA_DB_HOST": "localhost",
                "SANDRA_DB_NAME": "mydb",
                "SANDRA_DB_USER": "root",
                "SANDRA_DB_PASS": ""
            }
        }
    }
}
```

Restart Claude Desktop. The 8 Sandra tools appear in the available tools list.

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `SANDRA_ENV` | `default` | Sandra environment prefix (table names) |
| `SANDRA_DB_HOST` | `localhost` | Database host |
| `SANDRA_DB_NAME` | `sandra` | Database name |
| `SANDRA_DB_USER` | `root` | Database user |
| `SANDRA_DB_PASS` | _(empty)_ | Database password |
| `SANDRA_INSTALL` | `false` | If `true`, create tables on startup |

---

## 3. Available Tools (8)

### 3.1 `sandra_list_factories` — List data types

No parameters. Returns all registered factories.

**Example response:**
```json
[
    {"name": "animal", "entityIsa": "animal", "entityContainedIn": "animalFile"},
    {"name": "person", "entityIsa": "person", "entityContainedIn": "personFile"}
]
```

**Typical use:** Start here to discover what data is available in the graph.

---

### 3.2 `sandra_describe_factory` — Describe a type's schema

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |

**Example response:**
```json
{
    "name": "animal",
    "entityIsa": "animal",
    "entityContainedIn": "animalFile",
    "referenceFields": ["name", "breed", "age", "color"],
    "count": 42
}
```

**Typical use:** Understand a type's structure before searching or creating entities.

---

### 3.3 `sandra_search` — Full-text search

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |
| `query` | string | yes | Search text |
| `field` | string | no | Restrict search to this field only |
| `limit` | integer | no | Max results to return (default 50) |

Search is in-memory after loading. Supports multiple words (AND logic) with scoring: exact match (3), word start (2), contains (1).

**Example input:**
```json
{
    "factory": "animal",
    "query": "felix",
    "field": "name",
    "limit": 10
}
```

**Response:**
```json
{
    "items": [
        {"id": 42, "refs": {"name": "Felix", "breed": "Siamese", "age": "4"}}
    ],
    "total": 1
}
```

---

### 3.4 `sandra_get_entity` — Get entity by ID

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |
| `id` | integer | yes | Entity concept ID |

Returns the entity with its references. If the factory was registered with `brothers` or `joined` options, those are included.

**Response:**
```json
{
    "id": 42,
    "refs": {"name": "Felix", "breed": "Siamese", "age": "4"}
}
```

**Error:** Throws if the ID doesn't exist in the factory.

---

### 3.5 `sandra_traverse` — Graph traversal

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |
| `startId` | integer | yes | Starting entity concept ID |
| `verb` | string | yes | Verb (relationship type) to follow |
| `depth` | integer | no | Max traversal depth (default 10) |
| `direction` | string | no | `"forward"` (default) or `"backward"` (ancestors) |
| `algorithm` | string | no | `"bfs"` (default) or `"dfs"` |

**Example — find descendants:**
```json
{
    "factory": "categories",
    "startId": 100,
    "verb": "subCategoryOf",
    "depth": 5,
    "algorithm": "bfs"
}
```

**Example — find ancestors:**
```json
{
    "factory": "categories",
    "startId": 250,
    "verb": "subCategoryOf",
    "direction": "backward"
}
```

**Response:**
```json
{
    "entities": [
        {"id": 101, "refs": {"label": "Electronics"}},
        {"id": 102, "refs": {"label": "Smartphones"}}
    ],
    "hasCycle": false,
    "totalFound": 2
}
```

---

### 3.6 `sandra_create_entity` — Create an entity

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |
| `refs` | object | yes | Key-value pairs of reference data |

**Example:**
```json
{
    "factory": "animal",
    "refs": {"name": "Luna", "breed": "Persian", "age": "2"}
}
```

**Response:** The serialized entity with its new `id`.

---

### 3.7 `sandra_link_entities` — Link two entities

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Factory containing the source entity |
| `sourceId` | integer | yes | Source entity concept ID |
| `verb` | string | yes | Relationship verb |
| `target` | string/int | yes | Target concept name (string) or concept ID (int) |
| `refs` | object | no | References to attach to the link |

**Example:**
```json
{
    "factory": "animal",
    "sourceId": 42,
    "verb": "ownedBy",
    "target": "John",
    "refs": {"since": "2020"}
}
```

**Response:**
```json
{
    "linked": true,
    "source": 42,
    "verb": "ownedBy",
    "target": "John"
}
```

---

### 3.8 `sandra_update_entity` — Update an entity

| Parameter | Type | Required | Description |
|---|---|---|---|
| `factory` | string | yes | Registered factory name |
| `id` | integer | yes | Entity concept ID |
| `refs` | object | yes | Key-value pairs to update |

Only the provided references are modified. Others remain unchanged.

**Example:**
```json
{
    "factory": "animal",
    "id": 42,
    "refs": {"age": "5", "color": "grey"}
}
```

---

## 4. Custom Entry Point

The `bin/sandra-mcp` binary uses `registerAll()` to automatically expose all factories known to the system. For finer control, create a custom script:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

$system = new \SandraCore\System('myapp', false, 'localhost', 'mydb', 'root', '');

// Create factories with the exact schema you want
$animals = new \SandraCore\EntityFactory('animal', 'animalFile', $system);
$people = new \SandraCore\EntityFactory('person', 'personFile', $system);

$mcp = new \SandraCore\Mcp\McpServer($system);

// Register manually with options
$mcp->register('animals', $animals, [
    'brothers' => ['ownedBy'],
]);
$mcp->register('people', $people, [
    'joined' => ['owns' => $animals],
]);

$mcp->run();
```

The `brothers` and `joined` options control which relationships are included in `sandra_get_entity` responses.

---

## 5. Manual Testing via Command Line

The protocol is simple: one JSON line per message on stdin, one JSON line per response on stdout.

```bash
# Initialization handshake
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' | php bin/sandra-mcp
```

Expected response:
```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{}},"serverInfo":{"name":"sandra-mcp","version":"1.0.0"}}}
```

For a full interactive session:

```bash
php bin/sandra-mcp << 'EOF'
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}
{"jsonrpc":"2.0","method":"notifications/initialized"}
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sandra_list_factories","arguments":{}}}
EOF
```

---

## 6. Using from PHP Code (without STDIO)

For tests or in-app integration, use `dispatchMessage()` directly:

```php
$system = new \SandraCore\System('myapp', false, 'localhost', 'mydb', 'root', '');
$factory = new \SandraCore\EntityFactory('book', 'booksFile', $system);
$factory->populateLocal();

$mcp = new \SandraCore\Mcp\McpServer($system);
$mcp->register('books', $factory);
$mcp->boot();

// Call a tool via JSON-RPC
$response = $mcp->dispatchMessage([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/call',
    'params' => [
        'name' => 'sandra_search',
        'arguments' => ['factory' => 'books', 'query' => 'Hugo'],
    ],
]);

$content = json_decode($response['result']['content'][0]['text'], true);
// $content = ['items' => [...], 'total' => 1]
```

Or call the tool registry directly, bypassing JSON-RPC:

```php
$result = $mcp->getToolRegistry()->call('sandra_search', [
    'factory' => 'books',
    'query' => 'Hugo',
]);
// $result = ['items' => [...], 'total' => 1]
```

---

## 7. File Architecture

```
bin/sandra-mcp                              CLI entry point
src/SandraCore/Mcp/
    McpServer.php                           STDIO transport + JSON-RPC dispatch
    McpToolInterface.php                    Tool interface
    ToolRegistry.php                        Tool registration and dispatch
    EntitySerializer.php                    Shared serialization (refs, brothers, joined)
    Tools/
        ListFactoriesTool.php               sandra_list_factories
        DescribeFactoryTool.php             sandra_describe_factory
        SearchEntitiesTool.php              sandra_search
        GetEntityTool.php                   sandra_get_entity
        TraverseGraphTool.php               sandra_traverse
        CreateEntityTool.php                sandra_create_entity
        LinkEntitiesTool.php                sandra_link_entities
        UpdateEntityTool.php                sandra_update_entity
tests/McpServerTest.php                     18 tests, 92 assertions
```

---

## 8. Creating a Custom Tool

Implement `McpToolInterface`:

```php
<?php
namespace App\Mcp\Tools;

use SandraCore\Mcp\McpToolInterface;

class MyCustomTool implements McpToolInterface
{
    public function name(): string
    {
        return 'my_custom_tool';
    }

    public function description(): string
    {
        return 'Description visible to the LLM so it knows when to use this tool.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'param1' => ['type' => 'string', 'description' => 'A parameter'],
            ],
            'required' => ['param1'],
        ];
    }

    public function execute(array $args): mixed
    {
        return ['result' => 'Value for ' . $args['param1']];
    }
}
```

Then register it before `run()`:

```php
$mcp->boot();
$mcp->getToolRegistry()->register(new \App\Mcp\Tools\MyCustomTool());
$mcp->run();
```

---

## 9. Typical LLM Workflow

When an LLM agent connects, it typically follows this pattern:

1. **Discovery** — `sandra_list_factories` to see available data types
2. **Exploration** — `sandra_describe_factory` to understand a type's schema
3. **Search** — `sandra_search` to find specific entities
4. **Read** — `sandra_get_entity` for full entity details
5. **Navigate** — `sandra_traverse` to explore graph relationships
6. **Write** — `sandra_create_entity`, `sandra_update_entity`, `sandra_link_entities` to modify the graph

The agent can combine these steps freely. For example:
- "Find all Siamese cats" -> `sandra_search`
- "Who owns Felix?" -> `sandra_get_entity` + `sandra_traverse`
- "Add a new cat named Luna" -> `sandra_create_entity` + `sandra_link_entities`
