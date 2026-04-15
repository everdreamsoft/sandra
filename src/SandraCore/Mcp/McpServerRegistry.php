<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

/**
 * Caches McpServer instances per env.
 *
 * When a request arrives with a token mapped to a specific env, the transport
 * needs to route to a McpServer bound to that env's System. Each McpServer
 * holds its own factories, tools registry, and session state — so we cache
 * one per env to avoid rebuilding on every request.
 */
class McpServerRegistry
{
    private SystemRegistry $systemRegistry;
    private ?string $logFile;

    /** @var array<string, McpServer> env → McpServer */
    private array $cache = [];

    public function __construct(SystemRegistry $systemRegistry, ?string $logFile = null)
    {
        $this->systemRegistry = $systemRegistry;
        $this->logFile = $logFile;
    }

    /**
     * Get a McpServer for the given env. Lazy-instantiated and cached.
     * Each server runs its own discovery against the env's tables.
     *
     * @param int|null $version Datagraph version (7 = legacy Sandra 7, 8 = current).
     *                          Threaded through to the System built for this env.
     */
    public function get(
        string $env,
        ?string $dbHost = null,
        ?string $dbName = null,
        ?int $version = null
    ): McpServer {
        $v = $version ?? SystemRegistry::DEFAULT_VERSION;
        $key = ($dbHost ?? '*') . ':' . ($dbName ?? '*') . ':' . $env . ':v' . $v;

        if (!isset($this->cache[$key])) {
            $systemRegistry = $this->systemRegistry;
            $systemFactory = fn() => $systemRegistry->get($env, $dbHost, $dbName, $v);

            $server = new McpServer(null, $systemFactory, $this->logFile);
            $server->discover();
            $server->dispatchMessage(['method' => 'notifications/initialized', 'jsonrpc' => '2.0']);

            $this->cache[$key] = $server;
        }

        return $this->cache[$key];
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}
