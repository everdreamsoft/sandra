<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\Ql\AstExecutor;
use SandraCore\Ql\Parser;
use SandraCore\Ql\SandraQlSyntaxException;
use SandraCore\Ql\SandraQlValidationException;
use SandraCore\Ql\UnsupportedAstFeatureException;
use SandraCore\System;

/**
 * MCP tool `sandra_ql` — run a SandraQL query (textual) or its JSON AST.
 *
 * SandraQL is the unified query language shared with the JS library
 * (@everdreamsoft/sandraql). Pass either `query` (text) or `ast` (JSON).
 */
class SandraQlTool implements McpToolInterface, AclAwareToolInterface
{
    private System $system;
    private ?AccessContext $access = null;
    /** @var array<string, array{factory: \SandraCore\EntityFactory}> discovered factories, by is_a name */
    private array $factories;

    /**
     * @param array<string, array{factory: \SandraCore\EntityFactory}> $factories Discovered factory
     *        registry (same one the other MCP tools share). Used to resolve the container
     *        file when a query has no `IN file` clause — CsCannon-style factories don't
     *        follow the `{isa}_file` naming convention the executor falls back to.
     */
    public function __construct(System $system, array &$factories = [])
    {
        $this->system = $system;
        $this->factories = &$factories;
    }

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    public function name(): string
    {
        return 'sandra_ql';
    }

    public function description(): string
    {
        return 'Run a SandraQL query — the unified query language shared with the '
             . 'JS library. Pass `query` with SandraQL text (e.g. \'MATCH person '
             . 'WHERE age > 30 AND HAS likes -> coffee ORDER BY age DESC NUMERIC '
             . 'LIMIT 10\') or `ast` with the canonical JSON AST. Supports MATCH '
             . '[IN file], WHERE with = != > >= < <= LIKE IN, HAS verb -> target, '
             . 'NOT HAS, AND (OR is not yet supported by the PHP executor), '
             . 'ORDER BY [NUMERIC], LIMIT/OFFSET, SELECT fields [WITH STORAGE].';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SandraQL text. Mutually exclusive with `ast`.',
                ],
                'ast' => [
                    'type' => 'object',
                    'description' => 'Canonical SandraQL v1.0 JSON AST. Mutually exclusive with `query`.',
                ],
            ],
        ];
    }

    public function execute(array $args): mixed
    {
        $hasQuery = isset($args['query']) && is_string($args['query']) && $args['query'] !== '';
        $hasAst = isset($args['ast']) && is_array($args['ast']);
        if (!$hasQuery && !$hasAst) {
            return ['error' => 'Provide either `query` (SandraQL text) or `ast` (JSON AST).'];
        }

        try {
            $ast = $hasQuery ? Parser::parse($args['query']) : $args['ast'];

            // No explicit `IN file`: AstExecutor would guess `{isa}_file`, which is
            // wrong for factories whose container has another name (e.g.
            // blockchainEvent → blockchainEventFile). Use the discovered registry,
            // exactly like list/query/describe tools do.
            if (!isset($ast['match']['file']) && isset($ast['match']['isa'])) {
                $isa = $ast['match']['isa'];
                if (isset($this->factories[$isa]['factory'])) {
                    $ast['match']['file'] = (string)$this->factories[$isa]['factory']->entityContainedIn;
                }
            }

            $executor = (new AstExecutor($this->system))->withAccess($this->access);
            $entities = $executor->execute($ast);
            return [
                'count' => count($entities),
                'ast' => $ast,
                'entities' => AstExecutor::serialize($entities, $ast),
            ];
        } catch (SandraQlSyntaxException $e) {
            return ['error' => 'SandraQL syntax error: ' . $e->getMessage()];
        } catch (SandraQlValidationException $e) {
            return ['error' => 'Invalid SandraQL AST: ' . $e->getMessage()];
        } catch (UnsupportedAstFeatureException $e) {
            return ['error' => $e->getMessage() . ' — this query works on the JS library; PHP support is planned.'];
        }
    }
}
