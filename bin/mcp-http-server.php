#!/usr/bin/env php
<?php
/**
 * Sandra MCP HTTP Streamable Server
 *
 * Long-lived HTTP server that survives client disconnections.
 * Claude Code connects/reconnects as needed via HTTP.
 *
 * Usage:
 *   php bin/mcp-http-server.php [--port=8090] [--host=127.0.0.1]
 *
 * Then configure .mcp.json:
 *   {"type": "streamable-http", "url": "http://127.0.0.1:8090/mcp"}
 */
declare(strict_types=1);

set_time_limit(0);
ini_set('default_socket_timeout', '-1');

require_once __DIR__ . '/../vendor/autoload.php';

use SandraCore\System;
use SandraCore\Mcp\McpServer;
use SandraCore\Mcp\HttpTransport;

// ── CLI args ────────────────────────────────────────────────────────
$opts = getopt('', ['port:', 'host:']);
$port = (int)($opts['port'] ?? getenv('SANDRA_MCP_PORT') ?: 8090);
$host = $opts['host'] ?? getenv('SANDRA_MCP_HOST') ?: '127.0.0.1';

// ── Database config ─────────────────────────────────────────────────
$env = getenv('SANDRA_ENV') ?: 'mcp_';
$dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
$db = getenv('SANDRA_DB') ?: 'sandra';
$dbUser = getenv('SANDRA_DB_USER') ?: 'root';
$dbPass = getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : '';
$logFile = getenv('SANDRA_MCP_LOG') ?: '/tmp/sandra-mcp-http.log';
$install = (bool)(getenv('SANDRA_INSTALL') ?: false);

// ── Build MCP server ────────────────────────────────────────────────
$systemFactory = function () use ($env, $install, $dbHost, $db, $dbUser, $dbPass) {
    static $installed = false;
    $doInstall = $install && !$installed;
    $installed = true;
    return new System($env, $doInstall, $dbHost, $db, $dbUser, $dbPass);
};

$server = new McpServer(null, $systemFactory, $logFile);
$server->discover();

// Pre-boot discovery so first HTTP request is instant
$server->dispatchMessage(['method' => 'notifications/initialized', 'jsonrpc' => '2.0']);

// ── Start HTTP transport ────────────────────────────────────────────
$transport = new HttpTransport($server, $logFile);

echo "Sandra MCP HTTP server starting on http://$host:$port/mcp\n";
echo "Log: $logFile\n";
echo "Press Ctrl+C to stop.\n\n";

$transport->listen($host, $port);
