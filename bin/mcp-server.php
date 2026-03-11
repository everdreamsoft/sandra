#!/usr/bin/env php
<?php
declare(strict_types=1);

// MCP server must wait indefinitely for input
ini_set('default_socket_timeout', '-1');
set_time_limit(0);

require_once __DIR__ . '/../vendor/autoload.php';

use SandraCore\System;
use SandraCore\Mcp\McpServer;

$env = getenv('SANDRA_ENV') ?: 'mcp_';
$dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
$db = getenv('SANDRA_DB') ?: 'sandra';
$dbUser = getenv('SANDRA_DB_USER') ?: 'root';
$dbPass = getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : '';
$logFile = getenv('SANDRA_MCP_LOG') ?: '/tmp/sandra-mcp.log';
$install = (bool)(getenv('SANDRA_INSTALL') ?: false);

// Defer System creation — don't block startup with DB connections
$systemFactory = function () use ($env, $install, $dbHost, $db, $dbUser, $dbPass) {
    static $installed = false;
    $doInstall = $install && !$installed;
    $installed = true;
    return new System($env, $doInstall, $dbHost, $db, $dbUser, $dbPass);
};

$server = new McpServer(null, $systemFactory, $logFile);
$server->discover();
$server->run();
