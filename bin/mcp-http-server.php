#!/usr/bin/env php
<?php
/**
 * Sandra MCP HTTP Streamable Server
 *
 * Long-lived HTTP server that survives client disconnections.
 * Claude Code connects/reconnects as needed via HTTP.
 *
 * Usage:
 *   php bin/mcp-http-server.php [--port=8090] [--host=127.0.0.1] [--env=/path/to/.env]
 *                               [--version=7|8] [--token-env=/path/to/tokens.env]
 *
 * As a composer dependency:
 *   php vendor/everdreamsoft/sandra/bin/mcp-http-server.php --env=.env
 *
 * Legacy Sandra 7 datagraph with v8 token store in a separate DB:
 *   php vendor/everdreamsoft/sandra/bin/mcp-http-server.php \
 *       --env=.envSandra7 --token-env=.env --version=7
 *
 * Then configure .mcp.json:
 *   {"type": "streamable-http", "url": "http://127.0.0.1:8090/mcp"}
 */
declare(strict_types=1);

set_time_limit(0);
ini_set('default_socket_timeout', '-1');

// ── Autoloader: try project root first, then Sandra standalone ──────
$autoloadPaths = [
    __DIR__ . '/../../../autoload.php',   // vendor/everdreamsoft/sandra/bin/ → vendor/autoload.php
    __DIR__ . '/../vendor/autoload.php',  // sandra/bin/ → sandra/vendor/autoload.php
];
$autoloaded = false;
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoloaded = true;
        break;
    }
}
if (!$autoloaded) {
    fwrite(STDERR, "Error: Could not find autoload.php. Run composer install.\n");
    exit(1);
}

use SandraCore\Sandra7LegacySystem;
use SandraCore\System;
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\McpServerRegistry;
use SandraCore\Mcp\HttpTransport;
use SandraCore\Mcp\SystemRegistry;
use SandraCore\Mcp\TokenAuthService;
use SandraCore\Mcp\SessionStore;
use SandraCore\Mcp\SqlRateLimiter;

// ── CLI args ────────────────────────────────────────────────────────
$opts = getopt('', ['port:', 'host:', 'env:', 'version:', 'token-env:', 'no-oauth']);

// ── Load .env file ─────────────────────────────────────────────────
// Priority: --env flag → bin/.env → project root .env
$envPaths = array_filter([
    $opts['env'] ?? null,                           // --env=/path/to/.env
    __DIR__ . '/.env',                              // sandra/bin/.env (standalone)
    getcwd() . '/.env',                             // current working directory
]);
foreach ($envPaths as $envFile) {
    if ($envFile !== null && file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_contains($line, '=')) {
                // Only set if not already defined (env vars take precedence)
                [$key] = explode('=', $line, 2);
                if (getenv($key) === false) {
                    putenv($line);
                }
            }
        }
        break; // Use first .env found
    }
}
// ── Load --token-env file (local parse, no putenv) ─────────────────
// This is used only to build the auth/session store connection below —
// we never inject its vars into the process env, so it can't shadow the
// datagraph .env loaded above.
$tokenEnvFile = $opts['token-env'] ?? null;
/** @var array<string,string> $tokenVars */
$tokenVars = [];
if ($tokenEnvFile !== null && file_exists($tokenEnvFile)) {
    foreach (file($tokenEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $tokenVars[trim($k)] = trim($v);
    }
}

$port = (int)($opts['port'] ?? getenv('SANDRA_MCP_PORT') ?: 8090);
$host = $opts['host'] ?? getenv('SANDRA_MCP_HOST') ?: '127.0.0.1';

// ── Database config ─────────────────────────────────────────────────
// SANDRA_ENV: distinguish "unset" (→ default 'mcp_') from "set to empty"
// (→ '', which Sandra 7 legacy mode uses to mean "tables without suffix").
$envRaw = getenv('SANDRA_ENV');
$env = $envRaw !== false ? $envRaw : 'mcp_';

// Datagraph version: 7 = legacy Sandra 7 tables (Concept/Link/References/aux_dataStorage),
// 8 = current schema. CLI flag wins over env var.
$version = isset($opts['version'])
    ? (int)$opts['version']
    : (int)(getenv('SANDRA_DATAGRAPH_VERSION') ?: SystemRegistry::DEFAULT_VERSION);
$dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
$db = getenv('SANDRA_DB') ?: getenv('SANDRA_DB_DATABASE') ?: 'sandra';
$dbUser = getenv('SANDRA_DB_USER') ?: getenv('SANDRA_DB_USERNAME') ?: 'root';
$dbPass = getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : (getenv('SANDRA_DB_PASSWORD') !== false ? getenv('SANDRA_DB_PASSWORD') : '');
$logFile = getenv('SANDRA_MCP_LOG') ?: '/tmp/sandra-mcp-http.log';
$install = (bool)(getenv('SANDRA_INSTALL') ?: false);
$authToken = getenv('SANDRA_AUTH_TOKEN') ?: null;

// ── Build MCP server ────────────────────────────────────────────────
// Install if requested, otherwise deferred to SystemRegistry
if ($install) {
    $version === SystemRegistry::LEGACY_VERSION
        ? new Sandra7LegacySystem($env, true, $dbHost, $db, $dbUser, $dbPass)
        : new System($env, true, $dbHost, $db, $dbUser, $dbPass);
}

// Multi-env system registry for API multi-tenancy.
// $version sets the *default* datagraph version for bootstrap + tokens that
// don't specify one; individual tokens can still override via datagraph_version.
$systemRegistry = new SystemRegistry($dbHost, $db, $dbUser, $dbPass, $env, $version);

$systemFactory = fn() => $systemRegistry->get($env);

$server = new McpServer(null, $systemFactory, $logFile);
$server->discover();

// Pre-boot discovery so first HTTP request is instant
$server->dispatchMessage(['method' => 'notifications/initialized', 'jsonrpc' => '2.0']);

// ── Auth System (token + sessions storage) ─────────────────────────
// When --token-env= is passed, build a dedicated v8 System for the shared
// token store so it can live in a separate DB from the datagraph (required
// when the datagraph is Sandra 7 legacy). Otherwise fall back to the default
// datagraph System — historical single-DB behaviour.
// Auth is enabled when SANDRA_AUTH_TOKEN is set — this is the legacy contract.
// Other projects (claudia, eleonora, ...) rely on this: no token = no auth =
// open access. Don't break that. For sandra-docs and similar deploys that
// want per-token routing without a static fallback token, just set
// SANDRA_AUTH_TOKEN to ANY random string — the value is unused if no client
// presents it; the real auth happens via sandra_api_tokens.
$authSystem = null;
if ($authToken !== null) {
    if ($tokenVars !== []) {
        $tokenEnvRaw = $tokenVars['SANDRA_ENV'] ?? null;
        $tokenEnv    = $tokenEnvRaw ?? 'mcp_';
        $tokenHost   = $tokenVars['SANDRA_DB_HOST'] ?? $dbHost;
        $tokenDb     = $tokenVars['SANDRA_DB'] ?? $tokenVars['SANDRA_DB_DATABASE'] ?? $db;
        $tokenUser   = $tokenVars['SANDRA_DB_USER'] ?? $tokenVars['SANDRA_DB_USERNAME'] ?? $dbUser;
        $tokenPass   = $tokenVars['SANDRA_DB_PASS'] ?? $tokenVars['SANDRA_DB_PASSWORD'] ?? $dbPass;
        // Token store is always v8 — the schema only exists in the modern datagraph.
        $authSystem = new System($tokenEnv, $install, $tokenHost, $tokenDb, $tokenUser, $tokenPass);
    } else {
        if ($version === SystemRegistry::LEGACY_VERSION) {
            fwrite(STDERR, "Warning: auth enabled on a Sandra 7 legacy datagraph without --token-env=. The token store will be searched in the legacy DB and will fail. Pass --token-env=/path/to/v8.env\n");
        }
        $authSystem = $systemRegistry->getDefault();
    }
}

$authService = null;
if ($authToken !== null && $authSystem !== null) {
    $authService = new TokenAuthService(
        $authSystem->getConnection(),
        $authSystem->sharedTokenTable,
        $authToken,
        $env,
        $logFile
    );
}

// ── MCP server registry for per-token env routing ──────────────────
$mcpRegistry = new McpServerRegistry($systemRegistry, $logFile);

// ── Session store (persists sessions across process restarts) ──────
$sessionStore = null;
if ($authService !== null && $authSystem !== null) {
    $sessionStore = new SessionStore(
        $authSystem->getConnection(),
        $authSystem->sharedSessionsTable,
        $logFile
    );
}

// ── Start HTTP transport ────────────────────────────────────────────
// --no-oauth: don't advertise OAuth discovery. Clients with pre-shared Bearer
// tokens (e.g. sandra-docs visitors) won't try the browser OAuth dance and
// stall on the callback when their local listener isn't reachable.
$enableOAuth = !isset($opts['no-oauth']);
$transport = new HttpTransport($server, $logFile, $authToken, $authService, $systemRegistry, $mcpRegistry, $sessionStore, $enableOAuth);

// ── Per-token rate limiter (read=60, api_read=300, write=600 RPM) ───
// Active by default whenever a token store is configured. Fail-open: if the
// rate-limit table is unreachable, requests are let through rather than 429.
if ($authSystem !== null) {
    $transport->setRateLimiter(new SqlRateLimiter($authSystem->getConnection()));
}

echo "Sandra MCP HTTP server starting on http://$host:$port\n";
echo "  Endpoints: /mcp, /api/*, /.well-known/*, /authorize, /token\n";
echo "Datagraph: $dbHost/$db  (v$version" . ($version === SystemRegistry::LEGACY_VERSION ? " legacy" : "") . ", env='" . ($env === '' ? "" : $env) . "')\n";
if ($authSystem !== null && $tokenVars !== []) {
    echo "Token store: " . ($tokenVars['SANDRA_DB_HOST'] ?? $dbHost) . "/" . ($tokenVars['SANDRA_DB'] ?? $tokenVars['SANDRA_DB_DATABASE'] ?? $db) . "  (v8, env='" . ($tokenVars['SANDRA_ENV'] ?? 'mcp_') . "')\n";
} elseif ($authSystem !== null) {
    echo "Token store: (same DB as datagraph)\n";
}
echo "Log: $logFile\n";
echo "Auth: " . ($authToken ? "enabled (Bearer token required)" : "disabled (open access)") . "\n";
echo "OAuth discovery: " . ($enableOAuth ? "advertised" : "DISABLED (--no-oauth)") . "\n";
echo "Token table: " . ($authService ? "sandra_api_tokens (multi-env routing)" : "static token only") . "\n";
echo "Session table: " . ($sessionStore ? "sandra_mcp_sessions (persisted across restarts)" : "in-memory only") . "\n";
echo "Press Ctrl+C to stop.\n\n";

$transport->listen($host, $port);
