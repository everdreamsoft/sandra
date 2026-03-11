#!/usr/bin/env php
<?php
/**
 * MCP Server Stress Test
 *
 * Simulates a Claude Code MCP client to test connection stability.
 * Sends the full handshake + repeated tool calls with configurable delays.
 *
 * Usage:
 *   php bin/mcp-stress-test.php [--rounds=50] [--delay=2] [--timeout=10] [--chaos]
 *
 * Options:
 *   --rounds=N   Number of tool call rounds (default: 50)
 *   --delay=N    Seconds between rounds (default: 2)
 *   --timeout=N  Max seconds to wait for a response (default: 10)
 *   --chaos      Random delays (0-30s) between calls to simulate real agent pauses
 */

declare(strict_types=1);

// ── Parse CLI args ──────────────────────────────────────────────────
$opts = getopt('', ['rounds:', 'delay:', 'timeout:', 'chaos']);
$rounds = (int)($opts['rounds'] ?? 50);
$delay = (int)($opts['delay'] ?? 2);
$timeout = (int)($opts['timeout'] ?? 10);
$chaos = isset($opts['chaos']);

$env = getenv('SANDRA_ENV') ?: 'mcp_';
$dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
$db = getenv('SANDRA_DB') ?: 'sandra';
$dbUser = getenv('SANDRA_DB_USER') ?: 'root';
$dbPass = getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : '';

// ── Colors ──────────────────────────────────────────────────────────
function c(string $color, string $text): string {
    $codes = ['green' => '32', 'red' => '31', 'yellow' => '33', 'cyan' => '36', 'dim' => '2'];
    return "\033[" . ($codes[$color] ?? '0') . "m{$text}\033[0m";
}

function banner(string $msg): void {
    echo "\n" . c('cyan', "═══ $msg ═══") . "\n";
}

function ok(string $msg): void { echo c('green', '  ✓ ') . $msg . "\n"; }
function fail(string $msg): void { echo c('red', '  ✗ ') . $msg . "\n"; }
function info(string $msg): void { echo c('dim', "  · $msg") . "\n"; }

// ── Start the MCP server process ────────────────────────────────────
banner("MCP Stress Test — {$rounds} rounds, delay={$delay}s" . ($chaos ? ' (chaos mode)' : ''));

// Use stdbuf or php implicit_flush to force line-buffered stdout from the child process
$serverScript = escapeshellarg(__DIR__ . '/mcp-server.php');
$cmd = '/opt/homebrew/bin/php -d xdebug.mode=off -d display_errors=Off -d log_errors=Off -d implicit_flush=1 -d output_buffering=0 '
     . $serverScript;

$descriptors = [
    0 => ['pipe', 'r'],  // stdin  — child reads, we write
    1 => ['pipe', 'w'],  // stdout — child writes, we read
    2 => ['pipe', 'w'],  // stderr — child writes, we read
];

$envVars = [
    'SANDRA_ENV' => $env,
    'SANDRA_DB_HOST' => $dbHost,
    'SANDRA_DB' => $db,
    'SANDRA_DB_USER' => $dbUser,
    'SANDRA_DB_PASS' => $dbPass,
    'SANDRA_INSTALL' => '1',
    'SANDRA_MCP_LOG' => '/tmp/sandra-mcp-stress.log',
];

$process = proc_open($cmd, $descriptors, $pipes, __DIR__ . '/..', $envVars);
if (!is_resource($process)) {
    fail("Could not start MCP server process");
    exit(1);
}

$stdin = $pipes[0];
$stdout = $pipes[1];
$stderr = $pipes[2];

stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

$stats = [
    'sent' => 0,
    'received' => 0,
    'errors' => 0,
    'timeouts' => 0,
    'max_response_ms' => 0,
    'min_response_ms' => PHP_INT_MAX,
    'total_response_ms' => 0,
    'start_time' => microtime(true),
    'failures' => [],
];

// ── Helper: send JSON-RPC and read response ─────────────────────────
function sendRpc(array $msg, $stdin, $stdout, int $timeoutSec, array &$stats): ?array {
    $json = json_encode($msg, JSON_UNESCAPED_UNICODE);
    $written = @fwrite($stdin, $json . "\n");
    if ($written === false || $written === 0) {
        fail("WRITE FAILED — pipe broken");
        $stats['errors']++;
        $stats['failures'][] = ['type' => 'write_failed', 'id' => $msg['id'] ?? null, 'time' => date('H:i:s')];
        return null;
    }
    fflush($stdin);
    $stats['sent']++;

    // For notifications (no id), don't expect a response
    if (!isset($msg['id'])) {
        return ['_notification' => true];
    }

    $t0 = microtime(true);
    $buffer = '';

    while (true) {
        $remaining = $timeoutSec - (microtime(true) - $t0);
        if ($remaining <= 0) break;

        $read = [$stdout];
        $write = null;
        $except = null;
        $sec = (int)$remaining;
        $usec = (int)(($remaining - $sec) * 1000000);

        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) break; // stream error
        if ($ready === 0) continue;  // timeout on select, loop will check deadline

        $chunk = @fread($stdout, 262144);
        if ($chunk === false || $chunk === '') {
            usleep(1000);
            continue;
        }

        $buffer .= $chunk;
        // Try to parse complete JSON lines
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $elapsed = (microtime(true) - $t0) * 1000;
                $stats['received']++;
                $stats['total_response_ms'] += $elapsed;
                $stats['max_response_ms'] = max($stats['max_response_ms'], $elapsed);
                $stats['min_response_ms'] = min($stats['min_response_ms'], $elapsed);
                $decoded['_elapsed_ms'] = round($elapsed, 1);
                return $decoded;
            }
        }
    }

    $elapsed = round((microtime(true) - $t0) * 1000);
    fail("TIMEOUT after {$elapsed}ms waiting for id=" . ($msg['id'] ?? '?') . " (buffer: " . strlen($buffer) . " bytes)");
    $stats['timeouts']++;
    $stats['failures'][] = ['type' => 'timeout', 'id' => $msg['id'] ?? null, 'time' => date('H:i:s'), 'elapsed_ms' => $elapsed, 'buffer' => substr($buffer, 0, 200)];

    return null;
}

function checkProcessAlive($process): bool {
    $status = proc_get_status($process);
    return $status['running'];
}

// ── Phase 1: Handshake ──────────────────────────────────────────────
banner("Phase 1: Handshake");

$initMsg = [
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => ['roots' => new \stdClass()],
        'clientInfo' => ['name' => 'mcp-stress-test', 'version' => '1.0.0'],
    ],
    'jsonrpc' => '2.0',
    'id' => 0,
];

$resp = sendRpc($initMsg, $stdin, $stdout, $timeout, $stats);
if ($resp && isset($resp['result'])) {
    $version = $resp['result']['protocolVersion'] ?? '?';
    $serverName = $resp['result']['serverInfo']['name'] ?? '?';
    ok("initialize OK — server={$serverName}, protocol={$version} ({$resp['_elapsed_ms']}ms)");
} else {
    fail("initialize FAILED — aborting");
    proc_terminate($process);
    exit(1);
}

// notifications/initialized (no response expected)
sendRpc(['method' => 'notifications/initialized', 'jsonrpc' => '2.0'], $stdin, $stdout, $timeout, $stats);
ok("notifications/initialized sent");

// Small pause to let eager discover run
usleep(500000);

// tools/list
$resp = sendRpc(['method' => 'tools/list', 'jsonrpc' => '2.0', 'id' => 1], $stdin, $stdout, $timeout, $stats);
if ($resp && isset($resp['result']['tools'])) {
    $toolCount = count($resp['result']['tools']);
    $toolNames = array_map(fn($t) => $t['name'], $resp['result']['tools']);
    ok("tools/list OK — {$toolCount} tools ({$resp['_elapsed_ms']}ms)");
    info("Tools: " . implode(', ', $toolNames));
} else {
    fail("tools/list FAILED — aborting");
    proc_terminate($process);
    exit(1);
}

// ── Discover available factories from tools/list to use real names ───
$availableFactories = [];
foreach ($resp['result']['tools'] as $t) {
    if ($t['name'] === 'sandra_list_factories') {
        // We'll populate after first call
        break;
    }
}

// ── Phase 2: Endurance test ─────────────────────────────────────────
// Run tool calls for the full duration, with increasing idle gaps
$durationMin = max(1, (int)ceil($rounds * $delay / 60));
banner("Phase 2: Endurance test (~{$durationMin} min, {$rounds} calls)");

$toolCalls = [
    ['name' => 'sandra_list_factories', 'arguments' => new \stdClass()],
    ['name' => 'sandra_list_concepts', 'arguments' => ['limit' => 10]],
    ['name' => 'sandra_search', 'arguments' => ['query' => '%', 'limit' => 5]],
    ['name' => 'sandra_get_schema', 'arguments' => ['include_samples' => false]],
];

$id = 2;
$consecutiveFailures = 0;
$lastCallTime = microtime(true);

for ($round = 1; $round <= $rounds; $round++) {
    if (!checkProcessAlive($process)) {
        $idleSec = round(microtime(true) - $lastCallTime);
        fail("SERVER PROCESS DIED at round {$round} (after {$idleSec}s idle)");
        $stats['failures'][] = ['type' => 'process_died', 'round' => $round, 'idle_seconds' => $idleSec, 'time' => date('H:i:s')];
        break;
    }

    $tool = $toolCalls[($round - 1) % count($toolCalls)];
    $msg = [
        'method' => 'tools/call',
        'params' => $tool,
        'jsonrpc' => '2.0',
        'id' => $id++,
    ];

    $idleSec = round(microtime(true) - $lastCallTime, 1);
    $resp = sendRpc($msg, $stdin, $stdout, $timeout, $stats);
    $lastCallTime = microtime(true);

    $elapsed_total = (int)round(microtime(true) - $stats['start_time']);
    $elapsedStr = gmdate('i:s', $elapsed_total);

    if ($resp && isset($resp['result'])) {
        $isError = $resp['result']['isError'] ?? false;
        $elapsed = $resp['_elapsed_ms'];
        $consecutiveFailures = 0;

        $resultText = $resp['result']['content'][0]['text'] ?? '';
        $resultLen = strlen($resultText);

        if ($isError) {
            fail("[{$elapsedStr}] [{$round}/{$rounds}] {$tool['name']} — error (idle={$idleSec}s): " . substr($resultText, 0, 80));
            $stats['errors']++;
        } else {
            $status = $elapsed > 1000 ? c('yellow', 'SLOW') : c('green', 'OK');
            echo "  [{$elapsedStr}] [{$round}/{$rounds}] {$tool['name']} — {$status} {$elapsed}ms (idle={$idleSec}s)\n";
        }
    } else {
        $consecutiveFailures++;
        fail("[{$elapsedStr}] [{$round}/{$rounds}] {$tool['name']} — NO RESPONSE (idle={$idleSec}s)");
        if ($consecutiveFailures >= 3) {
            fail("3 consecutive failures — aborting");
            break;
        }
    }

    // Delay: use chaos mode or fixed delay
    if ($round < $rounds) {
        if ($chaos) {
            // Simulate real agent: mostly short pauses, sometimes long thinking
            $sleepSec = random_int(0, 60);
            if ($sleepSec > 15) {
                info("chaos: sleeping {$sleepSec}s (simulating agent thinking)...");
            }
        } else {
            $sleepSec = $delay;
        }
        sleep($sleepSec);
    }
}

// ── Phase 3: Escalating idle test ───────────────────────────────────
// Test increasingly long pauses: 30s, 60s, 120s, 180s, 300s
$idleTests = [30, 60, 120, 180, 300];
banner("Phase 3: Escalating idle gaps (" . implode('s, ', $idleTests) . "s)");

foreach ($idleTests as $idleSec) {
    if (!checkProcessAlive($process)) {
        fail("Process already dead before {$idleSec}s idle test");
        break;
    }

    info("Idle for {$idleSec}s...");
    $t0idle = microtime(true);

    // Monitor every 10s during idle
    $checks = (int)ceil($idleSec / 10);
    $died = false;
    for ($c = 0; $c < $checks; $c++) {
        sleep(min(10, $idleSec - $c * 10));
        if (!checkProcessAlive($process)) {
            $diedAt = ($c + 1) * 10;
            fail("Process DIED after {$diedAt}s idle (target was {$idleSec}s)");
            $stats['failures'][] = ['type' => 'idle_death', 'target_seconds' => $idleSec, 'died_at' => $diedAt, 'time' => date('H:i:s')];
            $died = true;
            break;
        }
    }

    if ($died) break;

    // Send a call after the idle
    $resp = sendRpc([
        'method' => 'tools/call',
        'params' => ['name' => 'sandra_list_factories', 'arguments' => new \stdClass()],
        'jsonrpc' => '2.0',
        'id' => $id++,
    ], $stdin, $stdout, $timeout, $stats);

    if ($resp && isset($resp['result']) && !($resp['result']['isError'] ?? false)) {
        ok("After {$idleSec}s idle → OK ({$resp['_elapsed_ms']}ms)");
    } else {
        fail("After {$idleSec}s idle → FAILED");
        $stats['failures'][] = ['type' => 'post_idle_fail', 'idle_seconds' => $idleSec, 'time' => date('H:i:s')];
        break;
    }
}

// ── Phase 4: Rapid fire ─────────────────────────────────────────────
banner("Phase 4: Rapid fire (20 calls, no delay)");

if (checkProcessAlive($process)) {
    $rapidFails = 0;
    for ($i = 0; $i < 20; $i++) {
        $tool = $toolCalls[$i % count($toolCalls)];
        $resp = sendRpc([
            'method' => 'tools/call',
            'params' => $tool,
            'jsonrpc' => '2.0',
            'id' => $id++,
        ], $stdin, $stdout, $timeout, $stats);

        if ($resp && isset($resp['result']) && !($resp['result']['isError'] ?? false)) {
            echo "  [rapid {$i}] {$tool['name']} — " . c('green', 'OK') . " {$resp['_elapsed_ms']}ms\n";
        } else {
            $rapidFails++;
            fail("[rapid {$i}] {$tool['name']} FAILED");
        }
    }
    ok("Rapid fire: " . (20 - $rapidFails) . "/20 succeeded");
}

// ── Cleanup ─────────────────────────────────────────────────────────
@fclose($stdin);
@fclose($stdout);
@fclose($stderr);
proc_terminate($process);

// ── Report ──────────────────────────────────────────────────────────
banner("Results");
$elapsed = round(microtime(true) - $stats['start_time'], 1);
$avgMs = $stats['received'] > 0 ? round($stats['total_response_ms'] / $stats['received'], 1) : 0;

echo "  Duration:      {$elapsed}s\n";
echo "  Sent:          {$stats['sent']} messages\n";
echo "  Received:      {$stats['received']} responses\n";
echo "  Errors:        " . ($stats['errors'] > 0 ? c('red', (string)$stats['errors']) : c('green', '0')) . "\n";
echo "  Timeouts:      " . ($stats['timeouts'] > 0 ? c('red', (string)$stats['timeouts']) : c('green', '0')) . "\n";
echo "  Avg response:  {$avgMs}ms\n";
echo "  Min response:  " . ($stats['min_response_ms'] < PHP_INT_MAX ? round($stats['min_response_ms'], 1) : 0) . "ms\n";
echo "  Max response:  " . round($stats['max_response_ms'], 1) . "ms\n";

if (!empty($stats['failures'])) {
    echo "\n  " . c('red', 'Failures:') . "\n";
    foreach ($stats['failures'] as $f) {
        echo "    - [{$f['time']}] {$f['type']}" . (isset($f['id']) ? " id={$f['id']}" : '') . "\n";
    }
}

$exitCode = ($stats['errors'] + $stats['timeouts'] > 0) ? 1 : 0;
echo "\n" . ($exitCode === 0 ? c('green', '  ALL PASSED') : c('red', '  SOME FAILURES')) . "\n\n";
exit($exitCode);
