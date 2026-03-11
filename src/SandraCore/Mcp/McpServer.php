<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\EntityFactory;
use SandraCore\Mcp\Tools\ListFactoriesTool;
use SandraCore\Mcp\Tools\DescribeFactoryTool;
use SandraCore\Mcp\Tools\SearchEntitiesTool;
use SandraCore\Mcp\Tools\GetEntityTool;
use SandraCore\Mcp\Tools\TraverseGraphTool;
use SandraCore\Mcp\Tools\CreateEntityTool;
use SandraCore\Mcp\Tools\LinkEntitiesTool;
use SandraCore\Mcp\Tools\UpdateEntityTool;
use SandraCore\Mcp\Tools\GetTripletsTool;
use SandraCore\Mcp\Tools\GetReferencesTool;
use SandraCore\Mcp\Tools\ListEntitiesTool;
use SandraCore\Mcp\Tools\GetSchemaTool;
use SandraCore\Mcp\Tools\CreateConceptTool;
use SandraCore\Mcp\Tools\CreateTripletTool;
use SandraCore\Mcp\Tools\CreateFactoryTool;
use SandraCore\Mcp\Tools\DeleteTripletTool;
use SandraCore\Mcp\Tools\BatchTool;
use SandraCore\Mcp\Tools\FindConceptTool;
use SandraCore\Mcp\Tools\ListConceptsTool;
use SandraCore\System;

class McpServer
{
    private ?System $system;
    private ?\Closure $systemFactory;
    private ToolRegistry $tools;
    private bool $initialized = false;
    private bool $discovered = false;
    private bool $pendingDiscover = false;
    private ?string $logFile = null;

    /** Track calls since last system refresh to avoid unnecessary bootFreshSystem */
    private int $callsSinceBoot = 0;
    private const CALLS_BEFORE_REFRESH = 5;

    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories = [];

    /** @var array<string, array{isa: string, cif: string, options: array}> Factory metadata (lightweight, no System ref) */
    private array $factoryMeta = [];

    private static array $defaultOptions = [
        'brothers' => [],
        'joined' => [],
    ];

    private ?string $instructions = null;

    /**
     * @param System $system Initial system for discovery/registration
     * @param \Closure|null $systemFactory Optional closure that returns a fresh System for each tool call
     * @param string|null $logFile Optional file path for persistent logging
     */
    public function __construct(?System $system = null, ?\Closure $systemFactory = null, ?string $logFile = null)
    {
        $this->system = $system;
        $this->systemFactory = $systemFactory;
        $this->logFile = $logFile;
        $this->tools = new ToolRegistry();
    }

    /**
     * Set custom instructions sent to the agent on connection (like a system prompt).
     * If not set, default Sandra instructions are used.
     */
    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    /** Get the instructions string (default or custom) */
    private function getInstructions(): string
    {
        if ($this->instructions !== null) {
            return $this->instructions;
        }

        return <<<'INSTRUCTIONS'
Sandra is a semantic graph database. Data is organized in two layers:

## System Concepts
Abstract vocabulary shared across the system (e.g. "healthy", "is_a", "friend").
Each concept has a unique ID and shortname. They serve as verbs and targets in the graph.

## Entities
Tabular data (e.g. clients, products, animals) managed by factories.
Each entity has two kinds of data:
- **References**: short key-value fields (name, email, price...) — structured, searchable.
- **Data storage**: a single long text field per entity (MEDIUMTEXT) for rich content like descriptions, notes, HTML, JSON, markdown, logs, etc. Not searchable but ideal for storing large text.

Use `include_storage: true` on get/search/list to retrieve it. Use the `storage` field on create/update to write it.

## How they connect
Triplets link everything: Entity(Alice) → verb(healthy) → target(concept).
Both entities and concepts live in the same graph and share concept IDs.

## Search workflow
1. Use `sandra_search` to find anything — results are tagged `type: "entity"` or `type: "system_concept"`
2. Use `sandra_get_triplets` with a concept ID to see all its relationships
3. Use `sandra_list_concepts` to browse/paginate the full concept vocabulary
4. Use `sandra_list_factories` to discover what entity types exist

## When to create a System Concept vs an Entity Factory
- **System concept**: for abstract, universal labels that the system uses as vocabulary — things any system could agree on. Examples: "urgent", "important", "personal", "social", "healthy", "archived". These are lightweight (just a shortname + ID in memory). Use them as verbs or tags in triplets.
- **Entity factory**: for tabular, data-rich collections that will hold many entries with structured fields. Examples: a "city" factory with refs like name, country, population; a "product" factory with refs like name, price, sku.

Rule of thumb: if it has its own data fields (name, description, etc.) and you expect many instances → entity factory. If it is a universal qualifier/label with no data of its own → system concept.

Example — tagging system:
- Create concept "tag" as the verb.
- Cities (Berlin, Geneva, Tokyo) → entity factory "city" with refs (name, country, ...), then triplet: Entity → tag → city_entity.
- Priorities (urgent, important) → system concepts, then triplet: Entity → tag → urgent.

## Batch operations
When creating multiple items, always prefer `sandra_batch` over repeated single calls.
It accepts concepts, entities and triplets arrays in one call. Use "$concept.N" or "$entity.N"
to reference items created earlier in the same batch (N = zero-based index in the array).

Always search before creating. Concepts and entities may already exist.
INSTRUCTIONS;
    }

    /** Get or create the System instance (lazy initialization) */
    private function getSystem(): System
    {
        if ($this->system === null && $this->systemFactory !== null) {
            $this->system = ($this->systemFactory)();
        }
        return $this->system;
    }

    /** Register a factory by name (like ApiHandler::register) */
    public function register(string $name, EntityFactory $factory, array $options = []): self
    {
        $mergedOptions = array_merge(self::$defaultOptions, $options);
        $this->factories[$name] = [
            'factory' => $factory,
            'options' => $mergedOptions,
        ];
        // Store lightweight metadata for rebuilding factories with a fresh System
        $this->factoryMeta[$name] = [
            'isa' => $factory->entityIsa,
            'cif' => $factory->entityContainedIn,
            'options' => $mergedOptions,
        ];
        return $this;
    }

    /** Auto-register all factories from FactoryManager (in-memory only) */
    public function registerAll(): self
    {
        foreach ($this->system->factoryManager->factoryRegistry as $factory) {
            $name = $factory->entityIsa ?? 'unknown';
            $this->register($name, $factory);
        }
        return $this;
    }

    /**
     * Auto-discover factories by scanning the database for distinct
     * (is_a, contained_in_file) pairs and register them.
     */
    public function discover(): self
    {
        $this->pendingDiscover = true;
        return $this;
    }

    /** Actually run discovery (called lazily after initialize handshake) */
    private function doDiscover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;
        $system = $this->getSystem();
        $discovery = new FactoryDiscovery($system, $this->logFile);
        foreach ($discovery->discover() as $name => $factory) {
            $this->register($name, $factory);
        }
        $this->boot();
        $this->callsSinceBoot = 0;
    }

    /** Boot all tools with the current system. */
    public function boot(): void
    {
        // If discover() was called but hasn't run yet, run it now
        // (doDiscover calls boot() internally, so we return after)
        if ($this->pendingDiscover && !$this->discovered) {
            $this->doDiscover();
            return;
        }

        $system = $this->getSystem();
        $this->tools = new ToolRegistry();
        $this->tools->register(new GetSchemaTool($this->factories, $system));
        $this->tools->register(new ListFactoriesTool($this->factories));
        $this->tools->register(new DescribeFactoryTool($this->factories, $system));
        $this->tools->register(new ListEntitiesTool($this->factories, $system));
        $this->tools->register(new SearchEntitiesTool($this->factories, $system));
        $this->tools->register(new GetEntityTool($this->factories, $system));
        $this->tools->register(new TraverseGraphTool($this->factories, $system));
        $this->tools->register(new CreateEntityTool($this->factories, $this->factoryMeta, $system));
        $this->tools->register(new LinkEntitiesTool($this->factories, $system));
        $this->tools->register(new UpdateEntityTool($this->factories, $system));
        $this->tools->register(new GetTripletsTool($system));
        $this->tools->register(new GetReferencesTool($system));
        $this->tools->register(new CreateConceptTool($system));
        $this->tools->register(new CreateTripletTool($system));
        $this->tools->register(new CreateFactoryTool($this->factories, $this->factoryMeta, $system));
        $this->tools->register(new DeleteTripletTool($system));
        $this->tools->register(new FindConceptTool($system));
        $this->tools->register(new ListConceptsTool($system));
        $this->tools->register(new BatchTool($this->factories, $this->factoryMeta, $system));
    }

    /** Rebuild factories and tools with a fresh System instance */
    private function bootFreshSystem(): void
    {
        $memBefore = memory_get_usage(true);
        $this->log('   bootFreshSystem: creating new System...');
        $this->system = ($this->systemFactory)();

        // Rebuild factory instances with the fresh System
        $this->factories = [];
        foreach ($this->factoryMeta as $name => $meta) {
            $factory = new EntityFactory($meta['isa'], $meta['cif'], $this->system);
            $this->factories[$name] = [
                'factory' => $factory,
                'options' => $meta['options'],
            ];
        }

        $this->boot();
        $this->callsSinceBoot = 0;
        $memAfter = memory_get_usage(true);
        $this->log('   bootFreshSystem: done (' . count($this->factoryMeta) . ' factories, memory: ' . round($memBefore / 1024 / 1024, 1) . 'MB -> ' . round($memAfter / 1024 / 1024, 1) . 'MB)');
    }

    /** Main STDIO loop — blocks until stdin closes */
    public function run(): void
    {
        // Reserve memory for OOM error handling
        $reservedMemory = str_repeat('x', 1024 * 1024);
        $logFile = $this->logFile;
        register_shutdown_function(function () use (&$reservedMemory, $logFile) {
            $reservedMemory = null; // free reserve so we can log
            $error = error_get_last();
            if ($error !== null && ($error['type'] & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR))) {
                $msg = "[sandra-mcp] FATAL SHUTDOWN: {$error['message']} in {$error['file']}:{$error['line']}\n";
                fwrite(STDERR, $msg);
                if ($logFile) {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg, FILE_APPEND);
                }
            }
        });

        if (!$this->pendingDiscover) {
            $this->boot();
        }

        // Ensure STDIN blocks indefinitely waiting for input
        stream_set_blocking(STDIN, true);

        $this->log('Server started, waiting for input... (memory: ' . round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB)');

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                $this->log('PARSE ERROR: ' . substr($line, 0, 200));
                $this->sendError(null, -32700, 'Parse error');
                continue;
            }

            $method = $msg['method'] ?? '?';
            $id = $msg['id'] ?? null;
            $toolName = ($method === 'tools/call') ? ($msg['params']['name'] ?? '?') : '';
            $this->log(">> RECV raw: " . substr($line, 0, 300));
            $this->log(">> RECV id=$id method=$method" . ($toolName ? " tool=$toolName" : ''));

            try {
                $this->dispatch($msg);
                $this->log("<< SENT id=$id method=$method OK (memory: " . round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB)');
            } catch (\Throwable $e) {
                $this->log("!! ERROR id=$id: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->log("   Trace: " . $e->getTraceAsString());
                if ($id !== null) {
                    $this->sendError($id, -32603, 'Internal error: ' . $e->getMessage());
                }
            }
        }

        $this->log('STDIN closed, server exiting.');
    }

    /** Dispatch a single JSON-RPC message (for testing without STDIO) */
    public function dispatchMessage(array $msg): ?array
    {
        $method = $msg['method'] ?? null;
        $id = $msg['id'] ?? null;

        // Notifications (no id) that we handle silently
        if ($id === null && in_array($method, ['notifications/initialized'], true)) {
            // Eager discover: run immediately after handshake so tools/list responds instantly
            if ($this->pendingDiscover && !$this->discovered) {
                $this->doDiscover();
            }
            return null;
        }

        return match ($method) {
            'initialize' => $this->buildInitializeResult($id, $msg['params'] ?? []),
            'notifications/initialized' => null,
            'tools/list' => $this->buildToolsListResult($id),
            'tools/call' => $this->buildToolsCallResult($id, $msg['params'] ?? []),
            'ping' => $this->buildResult($id, []),
            default => $id !== null
                ? $this->buildError($id, -32601, "Method not found: $method")
                : null,
        };
    }

    private function dispatch(array $msg): void
    {
        $response = $this->dispatchMessage($msg);
        if ($response !== null) {
            $this->send($response);
        }
    }

    private function buildInitializeResult($id, array $params): array
    {
        $this->initialized = true;
        $clientVersion = $params['protocolVersion'] ?? '2024-11-05';
        $this->log("   Client requested protocolVersion=$clientVersion");

        // Negotiate protocol version: respond with the latest we actually support
        $supportedVersions = ['2024-11-05', '2024-12-03', '2025-03-26', '2025-06-18', '2025-11-25'];
        $negotiatedVersion = '2024-11-05';
        foreach ($supportedVersions as $v) {
            if ($v <= $clientVersion) {
                $negotiatedVersion = $v;
            }
        }
        $this->log("   Negotiated protocolVersion=$negotiatedVersion");

        return $this->buildResult($id, [
            'protocolVersion' => $negotiatedVersion,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => [
                'name' => 'sandra-mcp',
                'version' => '1.0.0',
            ],
            'instructions' => $this->getInstructions(),
        ]);
    }

    private function buildToolsListResult($id): array
    {
        return $this->buildResult($id, ['tools' => $this->tools->listDefinitions()]);
    }

    private function buildToolsCallResult($id, array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        $this->log("   tool=$name args=" . json_encode($arguments, JSON_UNESCAPED_UNICODE));

        // Ensure system is booted (lazy discover if needed for one-shot patterns
        // where tools/call arrives before tools/list)
        if ($this->pendingDiscover && !$this->discovered) {
            $this->doDiscover();
        }

        // Refresh system periodically to prevent memory accumulation,
        // but skip when system is still fresh (saves ~30ms per call in one-shot mode)
        if ($this->systemFactory !== null && $this->callsSinceBoot >= self::CALLS_BEFORE_REFRESH) {
            $this->bootFreshSystem();
            $this->callsSinceBoot = 0;
        }

        $this->callsSinceBoot++;

        $t0 = microtime(true);
        try {
            $result = $this->tools->call($name, $arguments);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $this->log("   tool=$name completed in {$elapsed}ms (call #{$this->callsSinceBoot})");
            return $this->buildResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]],
            ]);
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $t0) * 1000);
            $this->log("   tool=$name FAILED in {$elapsed}ms: " . $e->getMessage());
            return $this->buildResult($id, [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ]);
        } finally {
            gc_collect_cycles();
        }
    }

    private function buildResult($id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function buildError($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function sendError($id, int $code, string $message): void
    {
        $this->send($this->buildError($id, $code, $message));
    }

    private function send(array $msg): void
    {
        $json = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $this->log("   >> SEND: $json");
        fwrite(STDOUT, $json . "\n");
        fflush(STDOUT);
    }

    private function log(string $message): void
    {
        $line = "[sandra-mcp] $message\n";
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        } else {
            fwrite(STDERR, $line);
        }
    }

    /** Expose the tool registry for testing */
    public function getToolRegistry(): ToolRegistry
    {
        return $this->tools;
    }

    /** Expose registered factories for testing */
    public function &getFactories(): array
    {
        return $this->factories;
    }
}
