<?php
/**
 * Example bootstrap file for SANDRA_MCP_AUDIT_BOOTSTRAP.
 *
 * Sandra core ships with zero runtime dependencies. The MCP server exposes
 * an opt-in `McpAuditLogger` interface that fires after every successful
 * `tools/call` (see HttpTransport::setAuditLogger). This example shows how
 * to bridge that hook to a PSR-3 logger — Monolog here, but any compliant
 * logger works (Laravel Log, Symfony, Stackdriver, …).
 *
 * Wiring:
 *
 *   1. In your host project (Claudia, Eleonora, Marketa, …) install
 *      Monolog (or your PSR-3 logger of choice):
 *
 *          composer require monolog/monolog
 *
 *   2. Copy this file into your host directory and adapt the logger
 *      construction to your stack (rotating files, syslog, stdout for
 *      systemd journal, …).
 *
 *   3. Point the MCP server at it via environment:
 *
 *          export SANDRA_MCP_AUDIT_BOOTSTRAP=/path/to/audit.php
 *          php /path/to/sandra/bin/mcp-http-server.php --env=.env
 *
 * Failure tolerance: HttpTransport catches any throw raised inside
 * logToolCall() so a broken adapter cannot crash a user response. Still,
 * write the adapter as best-effort (try/catch around I/O).
 *
 * What this example does NOT cover (yet):
 *   - 4xx rejections (auth failures, unknown sessions, bad JSON…). The
 *     current hook only fires for `tools/call`. Coverage of request-level
 *     events is a planned follow-up to the McpAuditLogger interface.
 *   - Sampling, rate-limiting, queueing. Add these in your adapter if
 *     traffic warrants it.
 */

// Adjust this require path to wherever your host project's autoload lives.
// For a host that vendors Sandra, this is typically the host's own
// composer autoload — not Sandra's, since Sandra has no logger dependency.
require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SandraCore\Mcp\McpAuditLogger;

// ── Build your PSR-3 logger ─────────────────────────────────────────
// Replace this block with whatever your stack already provides.
$logger = new \Monolog\Logger('mcp');
$logger->pushHandler(
    new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO)
);

// ── Adapter: McpAuditLogger → PSR-3 ─────────────────────────────────
// We use an anonymous class so the example stays self-contained. In a
// real host you'd extract this to its own class for testability.
return new class($logger) implements McpAuditLogger {
    public function __construct(private LoggerInterface $log) {}

    public function logToolCall(
        string $sessionId,
        ?array $routeInfo,
        string $toolName,
        array $arguments,
        bool $success,
        float $elapsedMs,
    ): void {
        $this->log->log(
            $success ? LogLevel::INFO : LogLevel::WARNING,
            'mcp.tool {tool} {status} {ms}ms',
            [
                'tool'              => $toolName,
                'status'            => $success ? 'ok' : 'error',
                'ms'                => $elapsedMs,
                // Truncate identifiers — full values turn the log file
                // into a secondary credential store.
                'session'           => substr($sessionId, 0, 8),
                'token_fp'          => isset($routeInfo['token_hash'])
                    ? substr((string) $routeInfo['token_hash'], 0, 12)
                    : null,
                'env'               => $routeInfo['env'] ?? null,
                'datagraph_version' => $routeInfo['datagraph_version'] ?? null,
                // Argument KEYS only — values may contain user content
                // (search queries, entity refs, free text). If you need
                // full payload capture, gate it behind an explicit flag
                // and ship the logs to a privacy-reviewed sink.
                'arg_keys'          => array_keys($arguments),
                'arg_count'         => count($arguments),
            ]
        );
    }
};
