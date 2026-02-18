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
use SandraCore\System;

class McpServer
{
    private System $system;
    private ToolRegistry $tools;
    private bool $initialized = false;

    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories = [];

    private static array $defaultOptions = [
        'brothers' => [],
        'joined' => [],
    ];

    public function __construct(System $system)
    {
        $this->system = $system;
        $this->tools = new ToolRegistry();
    }

    /** Register a factory by name (like ApiHandler::register) */
    public function register(string $name, EntityFactory $factory, array $options = []): self
    {
        $mergedOptions = array_merge(self::$defaultOptions, $options);
        $this->factories[$name] = [
            'factory' => $factory,
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
        $discovery = new FactoryDiscovery($this->system);
        foreach ($discovery->discover() as $name => $factory) {
            $this->register($name, $factory);
        }
        return $this;
    }

    /** Boot all tools. Call after all factories are registered. */
    public function boot(): void
    {
        $this->tools = new ToolRegistry();
        $this->tools->register(new ListFactoriesTool($this->factories));
        $this->tools->register(new DescribeFactoryTool($this->factories));
        $this->tools->register(new SearchEntitiesTool($this->factories));
        $this->tools->register(new GetEntityTool($this->factories));
        $this->tools->register(new TraverseGraphTool($this->factories, $this->system));
        $this->tools->register(new CreateEntityTool($this->factories));
        $this->tools->register(new LinkEntitiesTool($this->factories));
        $this->tools->register(new UpdateEntityTool($this->factories));
    }

    /** Main STDIO loop — blocks until stdin closes */
    public function run(): void
    {
        $this->boot();
        $this->log('Server started, waiting for input...');

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                $this->sendError(null, -32700, 'Parse error');
                continue;
            }
            $this->dispatch($msg);
        }
    }

    /** Dispatch a single JSON-RPC message (for testing without STDIO) */
    public function dispatchMessage(array $msg): ?array
    {
        $method = $msg['method'] ?? null;
        $id = $msg['id'] ?? null;

        // Notifications (no id) that we handle silently
        if ($id === null && in_array($method, ['notifications/initialized'], true)) {
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
        return $this->buildResult($id, [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => [
                'name' => 'sandra-mcp',
                'version' => '1.0.0',
            ],
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
        try {
            $result = $this->tools->call($name, $arguments);
            return $this->buildResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]],
            ]);
        } catch (\Throwable $e) {
            return $this->buildResult($id, [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ]);
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
        fwrite(STDOUT, json_encode($msg, JSON_UNESCAPED_UNICODE) . "\n");
        fflush(STDOUT);
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[sandra-mcp] $message\n");
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
