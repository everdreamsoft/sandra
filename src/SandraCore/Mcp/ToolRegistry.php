<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

class ToolRegistry
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    public function register(McpToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Return MCP tool definitions for tools/list */
    public function listDefinitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $definitions;
    }

    /** Call a tool by name */
    public function call(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Unknown tool: $name");
        }
        return $this->tools[$name]->execute($arguments);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Fetch a tool by name. Returns null when absent — useful for the
     * decoration pattern (wrap a base tool while preserving its interface).
     */
    public function get(string $name): ?McpToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string> Names of all currently registered tools, in insertion order.
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
