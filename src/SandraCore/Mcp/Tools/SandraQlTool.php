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
    /** Applied when the query carries no LIMIT — a remote client must opt in to more. */
    public const DEFAULT_LIMIT = 100;
    /** Hard ceiling: queries asking for more are clamped, never refused. */
    public const MAX_LIMIT = 1000;

    private System $system;
    private ?AccessContext $access = null;
    private int $defaultLimit;
    private int $maxLimit;
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

        // Guardrails live at the MCP boundary (not in AstExecutor) so the
        // embedded executor and the AST conformance contract shared with
        // sandra-js keep their unbounded semantics. Overridable per server.
        $envDefault = getenv('SANDRA_QL_DEFAULT_LIMIT');
        $envMax = getenv('SANDRA_QL_MAX_LIMIT');
        $this->defaultLimit = is_numeric($envDefault) && (int)$envDefault > 0 ? (int)$envDefault : self::DEFAULT_LIMIT;
        $this->maxLimit = is_numeric($envMax) && (int)$envMax > 0 ? (int)$envMax : self::MAX_LIMIT;
        if ($this->defaultLimit > $this->maxLimit) {
            $this->defaultLimit = $this->maxLimit;
        }
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
             . 'ORDER BY [NUMERIC], LIMIT/OFFSET, SELECT fields [WITH STORAGE]. '
             . "Queries without LIMIT get a server default of {$this->defaultLimit} results; "
             . "LIMIT is capped at {$this->maxLimit} (use OFFSET to paginate).";
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

            // Guardrails: a query without LIMIT gets the server default; a
            // LIMIT above the ceiling is clamped. Surfaced in the response so
            // agents know the result window was bounded.
            $requestedLimit = isset($ast['limit']) && is_int($ast['limit']) ? $ast['limit'] : null;
            $appliedLimit = $requestedLimit ?? $this->defaultLimit;
            $limitClamped = false;
            if ($appliedLimit > $this->maxLimit) {
                $appliedLimit = $this->maxLimit;
                $limitClamped = true;
            }
            $ast['limit'] = $appliedLimit;

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
            $result = [
                'count' => count($entities),
                'ast' => $ast,
                'entities' => AstExecutor::serialize($entities, $ast),
                'appliedLimit' => $appliedLimit,
            ];
            if ($requestedLimit === null) {
                $result['note'] = "No LIMIT in query - server default of {$this->defaultLimit} applied. "
                    . "Add LIMIT (max {$this->maxLimit}) or OFFSET to page through more results.";
            } elseif ($limitClamped) {
                $result['note'] = "Requested LIMIT {$requestedLimit} exceeds the server ceiling - "
                    . "clamped to {$this->maxLimit}. Use OFFSET to page through more results.";
            }
            return $result;
        } catch (SandraQlSyntaxException $e) {
            return ['error' => 'SandraQL syntax error: ' . $e->getMessage()];
        } catch (SandraQlValidationException $e) {
            return ['error' => 'Invalid SandraQL AST: ' . $e->getMessage()];
        } catch (UnsupportedAstFeatureException $e) {
            return ['error' => $e->getMessage() . ' — this query works on the JS library; PHP support is planned.'];
        }
    }
}
