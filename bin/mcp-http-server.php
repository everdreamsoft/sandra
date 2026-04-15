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
 *
 * As a composer dependency:
 *   php vendor/everdreamsoft/sandra/bin/mcp-http-server.php --env=.env
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

use SandraCore\System;
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\McpServerRegistry;
use SandraCore\Mcp\HttpTransport;
use SandraCore\Mcp\SystemRegistry;
use SandraCore\Mcp\TokenAuthService;

// ── CLI args ────────────────────────────────────────────────────────
$opts = getopt('', ['port:', 'host:', 'env:']);

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
$port = (int)($opts['port'] ?? getenv('SANDRA_MCP_PORT') ?: 8090);
$host = $opts['host'] ?? getenv('SANDRA_MCP_HOST') ?: '127.0.0.1';

// ── Database config ─────────────────────────────────────────────────
$env = getenv('SANDRA_ENV') ?: 'mcp_';
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
    new System($env, true, $dbHost, $db, $dbUser, $dbPass);
}

// Multi-env system registry for API multi-tenancy
$systemRegistry = new SystemRegistry($dbHost, $db, $dbUser, $dbPass, $env);

$systemFactory = fn() => $systemRegistry->get($env);

$server = new McpServer(null, $systemFactory, $logFile);
$server->discover();

// Pre-boot discovery so first HTTP request is instant
$server->dispatchMessage(['method' => 'notifications/initialized', 'jsonrpc' => '2.0']);

// ── Token auth service (unified for MCP + API) ─────────────────────
$authService = null;
if ($authToken !== null) {
    $defaultSystem = $systemRegistry->getDefault();
    $authService = new TokenAuthService(
        $defaultSystem->getConnection(),
        $defaultSystem->sharedTokenTable,
        $authToken,
        $env,
        $logFile
    );
}

// ── MCP server registry for per-token env routing ──────────────────
$mcpRegistry = new McpServerRegistry($systemRegistry, $logFile);

// ── Start HTTP transport ────────────────────────────────────────────
$transport = new HttpTransport($server, $logFile, $authToken, $authService, $systemRegistry, $mcpRegistry);

echo "Sandra MCP HTTP server starting on http://$host:$port\n";
echo "  Endpoints: /mcp, /api/*, /.well-known/*, /authorize, /token\n";
echo "Log: $logFile\n";
echo "Auth: " . ($authToken ? "enabled (Bearer token required)" : "disabled (open access)") . "\n";
echo "Token store: " . ($authService ? "sandra_api_tokens (multi-env routing)" : "static token only") . "\n";
echo "Press Ctrl+C to stop.\n\n";

$transport->listen($host, $port);
