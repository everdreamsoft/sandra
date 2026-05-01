# Customizing the Sandra MCP server

Sandra ships a turnkey MCP HTTP server (`bin/mcp-http-server.php`) that exposes
the full graph operation toolkit over JSON-RPC. For most projects that's enough.

This guide is for the case where it isn't — you're building a host that needs
to layer **per-user authorization**, **custom tools**, **tier-aware filtering**,
or **its own auth flow** on top of Sandra's MCP primitives.

The lib is designed for this. None of the customizations below require forking
Sandra core; they all plug in via public interfaces and additive hooks.

---

## 1. The mental model

Sandra core is **a library**, not a binary. The bundled `bin/mcp-http-server.php`
is one example of how to compose its parts:

```
SandraCore\Mcp
├── HttpTransport         ← HTTP + SSE wire layer + auth + sessions
├── McpServer             ← MCP protocol dispatcher + tool registry
├── McpToolInterface      ← contract for tools
├── Tools/                ← built-in tools (sandra_search, sandra_get_triplets, ...)
├── TokenAuthService      ← validate + route Bearer tokens against sandra_api_tokens
├── McpAuditLogger        ← optional hook called per tool/call
├── RateLimiter           ← optional hook called pre-dispatch
└── OAuthProvider         ← optional OAuth 2.1/PKCE flow
```

You can use any subset. A custom host typically:

- **Keeps**: `McpServer`, `Tools/*`, `EntityFactory`, `System`, `TokenAuthService`
- **Replaces**: `HttpTransport` (with framework-native routing), `OAuthProvider`
  (with app-specific landing flow)
- **Implements**: `McpAuditLogger`, `RateLimiter`, custom tools

The rest of this doc shows how.

---

## 2. Three customization patterns

### Pattern A — Tool replacement

Override a default tool with your own implementation. Useful when behavior
diverges significantly (e.g., your `sandra_get_triplets` filters per-user
permissions before returning).

```php
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\Tools\GetTripletsTool;
use App\Mcp\AclGetTripletsTool;

$server = new McpServer($system, /* systemFactory */ null, $logFile);
$server->boot();   // registers all defaults

// Replace the default. allowOverride = true tells register() to overwrite.
$server->register(
    'sandra_get_triplets',
    new AclGetTripletsTool($system, $currentUser),
    allowOverride: true,
);
```

Your `AclGetTripletsTool` implements `McpToolInterface` from scratch, free to
make any DB query, return any shape, enforce any rule.

### Pattern B — Tool decoration

Wrap an existing tool to pre/post-process its data. Useful for filters that
don't change the tool's logic, just constrain its input or output.

```php
namespace App\Mcp;

use SandraCore\Mcp\McpToolInterface;
use SandraCore\Mcp\Tools\GetTripletsTool;

class AclTripletsDecorator implements McpToolInterface
{
    public function __construct(
        private GetTripletsTool $base,
        private CurrentUser $user,
    ) {}

    public function name(): string { return $this->base->name(); }
    public function description(): string { return $this->base->description(); }
    public function inputSchema(): array { return $this->base->inputSchema(); }

    public function execute(array $args): mixed
    {
        $result = $this->base->execute($args);
        return $this->filterTriplets($result, $this->user);
    }

    private function filterTriplets(array $result, CurrentUser $user): array
    {
        $result['triplets'] = array_values(array_filter(
            $result['triplets'] ?? [],
            fn ($t) => $user->canSeeTriplet($t),
        ));
        return $result;
    }
}
```

Decorators preserve the public interface, so they're safe drop-in replacements.

### Pattern C — Custom tools

Add tools that don't exist in the lib at all. Useful for app-specific
operations (`sandra_docs_funnel_stats`, `crm_lead_score`, etc.).

```php
class FunnelStatsTool implements McpToolInterface
{
    public function name(): string { return 'sandra_docs_funnel_stats'; }
    public function description(): string {
        return 'Aggregate visitor → lead → contributor conversion over a date range.';
    }
    public function inputSchema(): array { /* JSON Schema */ }
    public function execute(array $args): mixed { /* ... */ }
}

$server->register('sandra_docs_funnel_stats', new FunnelStatsTool(...));
```

Custom tools are surfaced in `tools/list` alongside the built-ins.

---

## 3. Per-tier ACL: a complete recipe

Goal: anonymous visitors can read the docs graph with restricted tools;
contributors can also write to specific factories. All in a Laravel-hosted MCP
endpoint.

### Step 1 — Build a per-request `McpServer`

Each MCP request authenticates a different visitor. Build a fresh server
scoped to that visitor's tier — share-nothing, no leak risk between users.

```php
namespace App\Http\Controllers;

use SandraCore\Mcp\McpServer;
use App\Services\Sandra\SystemContent;

class McpController
{
    public function handle(Request $request, SystemContent $system, ToolFactory $tools)
    {
        $tier = $request->session()->get('tier', 'anon');
        $body = $request->json()->all();

        $server = $tools->buildServer($system, $tier);
        $response = $server->dispatchMessage($body);

        return response()->json($response);
    }
}
```

### Step 2 — `ToolFactory::buildServer()` wires the server per tier

```php
namespace App\Services\Mcp;

use SandraCore\Mcp\McpServer;
use SandraCore\System;

class ToolFactory
{
    public function buildServer(System $system, string $tier): McpServer
    {
        $server = new McpServer($system, null, storage_path('logs/mcp.log'));

        // Restrict the visible tool surface per tier.
        $server->setToolAllowlist($this->allowlistFor($tier));

        $server->boot();

        // Override read tools with ACL-aware decorators.
        $server->register('sandra_get_triplets',
            new AclTripletsDecorator(
                $server->getTool('sandra_get_triplets'),
                tier: $tier,
            ),
            allowOverride: true,
        );

        // Contributors only: register a custom comment-creation tool.
        if ($tier === 'contributor') {
            $server->register('sandra_docs_post_comment', new PostCommentTool($system));
        }

        return $server;
    }

    private function allowlistFor(string $tier): array
    {
        return match ($tier) {
            'anon' => [
                'sandra_search', 'sandra_get_triplets', 'sandra_list_concepts',
                'sandra_list_entities', 'sandra_list_factories',
            ],
            'lead' => [
                'sandra_search', 'sandra_get_triplets', 'sandra_list_concepts',
                'sandra_list_entities', 'sandra_list_factories', 'sandra_describe_factory',
                'sandra_get_entity', 'sandra_get_references', 'sandra_traverse',
            ],
            'contributor' => null, // null = all default tools allowed
        };
    }
}
```

### Step 3 — Auth middleware resolves the tier

```php
namespace App\Http\Middleware;

use SandraCore\Mcp\TokenAuthService;

class AuthenticateMcp
{
    public function __construct(private TokenAuthService $auth) {}

    public function handle(Request $request, Closure $next)
    {
        $token = $this->extractBearer($request);
        $route = $this->auth->validateAndRoute($token ?? '');
        if ($route === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Map scopes back to a tier for downstream ACL.
        $request->session()->put('tier', $this->tierFromScopes($route['scopes']));
        $request->session()->put('mcp_route', $route);

        return $next($request);
    }

    private function tierFromScopes(array $scopes): string
    {
        if (in_array('mcp:w', $scopes, true)) return 'contributor';
        if (in_array('api:r', $scopes, true)) return 'lead';
        return 'anon';
    }
}
```

### Step 4 — Plug the audit logger and rate limiter

These are middleware concerns, not McpServer's. Apply them in the controller
or a dedicated pipeline class:

```php
class McpController
{
    public function handle(Request $request, /* ... */, RateLimiter $limiter, McpAuditLogger $audit)
    {
        $route = $request->session()->get('mcp_route');
        if (! $limiter->allow($route['token_hash'], $route['scopes'])) {
            return response()->json(['error' => 'rate_limit_exceeded'], 429, [
                'Retry-After' => '60',
            ]);
        }

        $body = $request->json()->all();
        $tier = $this->tierFromScopes($route['scopes']);
        $server = $this->tools->buildServer($system, $tier);

        $start = microtime(true);
        $response = $server->dispatchMessage($body);
        $elapsed = (microtime(true) - $start) * 1000;

        if (($body['method'] ?? '') === 'tools/call') {
            $audit->logToolCall(
                sessionId: $request->header('Mcp-Session-Id', ''),
                routeInfo: $route,
                toolName: $body['params']['name'] ?? '?',
                arguments: $body['params']['arguments'] ?? [],
                success: ! isset($response['error']),
                elapsedMs: $elapsed,
            );
        }

        return response()->json($response);
    }
}
```

`SqlRateLimiter` is the default implementation shipped with the lib; bind any
implementation of `RateLimiter` and `McpAuditLogger` in your container.

---

## 4. Replacing the OAuth flow with your own landing

Sandra core's `OAuthProvider` does basic OAuth 2.1 PKCE with a static admin
token. To layer your own user-facing flow (tier picker, magic-link email,
funnel integration), replace it with your framework's routes and skip
`OAuthProvider` entirely.

### Configure `bin/mcp-http-server.php` (or your own entry script)

```bash
php bin/mcp-http-server.php --port=8001 --no-oauth
```

`--no-oauth` disables the `/.well-known/oauth-*` endpoints and OAuth
authorization endpoints. The server still validates Bearer tokens via
`TokenAuthService`. Your framework serves the OAuth flow at higher level.

### Implement the OAuth flow in your framework

You expose:

- `GET /.well-known/oauth-authorization-server` — metadata pointing to your endpoints
- `GET /.well-known/oauth-protected-resource` — metadata for clients
- `GET /oauth/authorize` — your branded landing page (tier picker, signup, login)
- `POST /oauth/authorize/{tier}` — handlers per tier (anon = direct issue, lead = magic link, etc.)
- `GET /oauth/callback` — magic-link / external auth callback
- `POST /oauth/token` — exchange `code` for token (returns a real `tok_...`
  row from `sandra_api_tokens`, not a JWT, so MCP transport can validate it)

Sandra core stays unaware. Tokens you issue land in `sandra_api_tokens`;
clients present them via `Authorization: Bearer <token>` to your `/mcp`
endpoint, which validates via `TokenAuthService` like any other route.

---

## 5. The hooks Sandra core provides

| Hook | Where | What it gives you |
|---|---|---|
| `McpToolInterface` | `SandraCore\Mcp\McpToolInterface` | Contract for custom tools |
| `McpServer::register($name, $tool, $allowOverride)` | post-`boot()` | Add or replace tools |
| `McpServer::setToolAllowlist(?array)` | pre-`boot()` | Restrict default tool registration |
| `McpServer::getTool($name): ?McpToolInterface` | any time | Fetch a tool to wrap it |
| `McpAuditLogger` interface | `SandraCore\Mcp` | Per-tool-call telemetry |
| `RateLimiter` interface + `SqlRateLimiter` | `SandraCore\Mcp` | Per-token throttling |
| `TokenAuthService::validateAndRoute($token)` | any time | Resolve a Bearer to env + scopes |
| `TokenAuthService::requiredScope($endpoint, $method, $body)` | any time | Decide if a request needs `mcp:r` or `mcp:w` |
| `HttpTransport::setAuditLogger / setRateLimiter` | constructor | When using the bundled HTTP transport |
| `HttpTransport` ctor `enableOAuth: false` | constructor | Disable OAuth advertisement |
| `--no-oauth` flag on `bin/mcp-http-server.php` | CLI | Same as above for the bundled binary |

---

## 6. Auditing tool subclassability

Built-in tools live in `src/SandraCore/Mcp/Tools/`. All implement
`McpToolInterface`, so **decoration is always safe** — wrap the tool, delegate
to `parent::execute()` or to an injected base instance, post-process the
return value.

Subclassing (extending and overriding internal helpers) currently has
limited support: most internal helpers are `private`. If you need to extend a
tool's internals rather than wrap it, use Pattern A (replace) and inline the
logic you need from the parent.

If your project frequently subclasses, consider opening a PR to change
`private` → `protected` on the relevant helpers — non-breaking, additive.

---

## 7. Common pitfalls

- **Forgot `--no-oauth`** : if you serve your own OAuth at the framework level
  AND the bundled HTTP transport is also advertising OAuth, clients see two
  conflicting `WWW-Authenticate` headers and may pick the wrong one. Disable
  one explicitly.

- **Sharing a single `McpServer` across requests for a multi-tenant host** :
  tool registration is mutable and shared. Either build a fresh `McpServer`
  per request (ms-cheap, recommended) or guard tool overrides with a lock.

- **ACL inside tools instead of pre-dispatch** : easier to enforce at the
  authentication / scope layer when possible, since auth runs once per
  request. Tool-level ACL is for fine-grained filtering (per-row, per-triplet)
  that auth scopes can't express.

- **Cross-graph triplet targets** : Sandra triplets only resolve within a
  single datagraph. If your custom tool wants to surface relations between
  graphs, store cross-graph references as scalar refs (concept ID), not
  triplets. The MCP layer doesn't enforce this — your tool's data model does.

- **`allowOverride: false` is the default for `register()`**. Calling
  `register()` twice with the same name silently keeps the first one (or
  errors, depending on lib version). Be explicit when intentional.

- **`tools/list` reflects what's registered**, including overrides. If you
  rename a tool by registering with a new name AND leaving the old, the
  client sees both.

---

## 8. Reference: a minimal Laravel-hosted MCP

A complete reference implementation lives at `htdocs/sandra-docs/`:

| File | Role |
|---|---|
| `app/Http/Controllers/McpController.php` | Per-request server build + dispatch |
| `app/Http/Middleware/AuthenticateMcp.php` | Bearer → tier resolution |
| `app/Services/Mcp/ToolFactory.php` | Per-tier server composition |
| `app/Services/Sandra/DocsMcpAuditLogger.php` | Audit hook (writes to telemetry datagraph) |
| `app/Mcp/Tools/AclTripletsDecorator.php` | Decorator example |
| `app/Mcp/Tools/PostCommentTool.php` | Custom tool example (contributor-only) |
| `routes/web.php` | `/mcp` + `/oauth/*` routes |

Use it as a starting point for your own host.

---

## 9. When to use the bundled binary instead

`bin/mcp-http-server.php` remains the right choice when:

- You don't have a web framework already
- You don't need per-user ACL beyond scopes
- You want `--no-oauth` + static `SANDRA_AUTH_TOKEN` for simple deployments
- You're prototyping Sandra integration before committing to a framework

It also serves as the canonical example of how to compose Sandra's MCP
primitives end-to-end.
