<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

interface McpToolInterface
{
    public function name(): string;

    public function description(): string;

    /** Return JSON Schema for input parameters */
    public function inputSchema(): array;

    /** Execute the tool and return result data */
    public function execute(array $args): mixed;
}
