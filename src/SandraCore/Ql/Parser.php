<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/**
 * SandraQL recursive-descent parser — 1:1 port of parser.ts from sandra-js
 * (same method names, same normalization). Text → canonical AST as a PHP
 * associative array, JSON-identical to the JS output (shared goldens in
 * tests/conformance/parser.json).
 */
class Parser
{
    /** @var array<int, array{kind:string, value:string, line:int, column:int}> */
    private array $tokens;
    private int $pos = 0;

    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /** Parse SandraQL text into the canonical AST array. */
    public static function parse(string $input): array
    {
        return (new self(Lexer::tokenize($input)))->parseQuery();
    }

    private function peek(int $offset = 0): array
    {
        return $this->tokens[min($this->pos + $offset, count($this->tokens) - 1)];
    }

    private function next(): array
    {
        $t = $this->peek();
        if ($t['kind'] !== 'EOF') {
            $this->pos++;
        }
        return $t;
    }

    private function error(string $message, ?array $token = null): void
    {
        $t = $token ?? $this->peek();
        throw new SandraQlSyntaxException($message, $t['line'], $t['column']);
    }

    private function expectKeyword(string $word): array
    {
        $t = $this->peek();
        if ($t['kind'] !== 'KEYWORD' || $t['value'] !== $word) {
            $got = $t['kind'] === 'EOF' ? 'end of input' : "\"{$t['value']}\"";
            $this->error("Expected $word, got $got");
        }
        return $this->next();
    }

    private function atKeyword(string $word): bool
    {
        $t = $this->peek();
        return $t['kind'] === 'KEYWORD' && $t['value'] === $word;
    }

    private function expectIdent(): string
    {
        $t = $this->peek();
        if ($t['kind'] !== 'IDENT') {
            $got = $t['kind'] === 'EOF' ? 'end of input' : "\"{$t['value']}\"";
            $this->error("Expected an identifier, got $got");
        }
        return $this->next()['value'];
    }

    private function expectNumber(): int|float
    {
        $t = $this->peek();
        if ($t['kind'] !== 'NUMBER') {
            $this->error("Expected a number, got \"{$t['value']}\"");
        }
        $this->next();
        return self::toNumber($t['value']);
    }

    private static function toNumber(string $raw): int|float
    {
        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    private function parseQuery(): array
    {
        if ($this->atKeyword('TRAVERSE')) {
            throw new UnsupportedAstFeatureException('traverse (reserved for a future version)');
        }
        $this->expectKeyword('MATCH');
        $isa = $this->expectIdent();
        $query = [
            'sandraql' => '1.0',
            'type' => 'query',
            'match' => ['isa' => $isa],
        ];
        if ($this->atKeyword('IN')) {
            $this->next();
            $query['match']['file'] = $this->expectIdent();
        }
        if ($this->atKeyword('WHERE')) {
            $this->next();
            $query['where'] = $this->parseOrExpr();
        }
        if ($this->atKeyword('ORDER')) {
            $this->next();
            $this->expectKeyword('BY');
            $query['order'] = [$this->parseOrderTerm()];
            while ($this->peek()['kind'] === 'COMMA') {
                $this->next();
                $query['order'][] = $this->parseOrderTerm();
            }
        }
        if ($this->atKeyword('LIMIT')) {
            $this->next();
            $query['limit'] = $this->expectNumber();
        }
        if ($this->atKeyword('OFFSET')) {
            $this->next();
            $query['offset'] = $this->expectNumber();
        }
        if ($this->atKeyword('SELECT')) {
            $this->next();
            $query['select'] = $this->parseSelect();
        }
        $t = $this->peek();
        if ($t['kind'] !== 'EOF') {
            $this->error("Unexpected \"{$t['value']}\" after end of query");
        }
        return $query;
    }

    private function parseOrExpr(): array
    {
        $parts = [$this->parseAndExpr()];
        while ($this->atKeyword('OR')) {
            $this->next();
            $parts[] = $this->parseAndExpr();
        }
        return count($parts) === 1 ? $parts[0] : ['or' => $parts];
    }

    private function parseAndExpr(): array
    {
        $parts = [$this->parseNotExpr()];
        while ($this->atKeyword('AND')) {
            $this->next();
            $parts[] = $this->parseNotExpr();
        }
        return count($parts) === 1 ? $parts[0] : ['and' => $parts];
    }

    private function parseNotExpr(): array
    {
        if ($this->atKeyword('NOT')) {
            $this->next();
            return ['not' => $this->parseNotExpr()];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        if ($this->peek()['kind'] === 'LPAREN') {
            $this->next();
            $inner = $this->parseOrExpr();
            if ($this->peek()['kind'] !== 'RPAREN') {
                $this->error('Expected ")"');
            }
            $this->next();
            return $inner;
        }
        if ($this->atKeyword('HAS')) {
            return $this->parseHasPredicate();
        }
        return $this->parseRefPredicate();
    }

    private function parseHasPredicate(): array
    {
        $this->expectKeyword('HAS');
        $has = [];
        if ($this->peek()['kind'] === 'ARROW') {
            $this->next();
            $has['target'] = $this->parseConceptRef();
        } else {
            $has['verb'] = $this->parseConceptRef();
            if ($this->peek()['kind'] === 'ARROW') {
                $this->next();
                $has['target'] = $this->parseConceptRef();
            }
        }
        return ['has' => $has];
    }

    private function parseConceptRef(): string|int|float
    {
        $t = $this->peek();
        if ($t['kind'] === 'IDENT' || $t['kind'] === 'STRING') {
            $this->next();
            return $t['value'];
        }
        if ($t['kind'] === 'NUMBER') {
            $this->next();
            return self::toNumber($t['value']);
        }
        $this->error("Expected a concept shortname or id, got \"{$t['value']}\"");
        return ''; // unreachable
    }

    private function parseRefPredicate(): array
    {
        $ref = $this->expectIdent();
        $t = $this->peek();
        if ($t['kind'] === 'KEYWORD' && $t['value'] === 'LIKE') {
            $this->next();
            return ['ref' => $ref, 'op' => 'LIKE', 'value' => $this->parseValue()];
        }
        if ($t['kind'] === 'KEYWORD' && $t['value'] === 'IN') {
            $this->next();
            if ($this->peek()['kind'] !== 'LPAREN') {
                $this->error('Expected "(" after IN');
            }
            $this->next();
            $values = [$this->parseValue()];
            while ($this->peek()['kind'] === 'COMMA') {
                $this->next();
                $values[] = $this->parseValue();
            }
            if ($this->peek()['kind'] !== 'RPAREN') {
                $this->error('Expected ")" to close IN list');
            }
            $this->next();
            return ['ref' => $ref, 'op' => 'IN', 'value' => $values];
        }
        if ($t['kind'] === 'OP') {
            $this->next();
            return ['ref' => $ref, 'op' => $t['value'], 'value' => $this->parseValue()];
        }
        $this->error("Expected a comparison operator after \"$ref\", got \"{$t['value']}\"");
        return []; // unreachable
    }

    private function parseValue(): string|int|float|bool|null
    {
        $t = $this->peek();
        if ($t['kind'] === 'STRING') { $this->next(); return $t['value']; }
        if ($t['kind'] === 'NUMBER') { $this->next(); return self::toNumber($t['value']); }
        if ($t['kind'] === 'KEYWORD' && $t['value'] === 'TRUE') { $this->next(); return true; }
        if ($t['kind'] === 'KEYWORD' && $t['value'] === 'FALSE') { $this->next(); return false; }
        if ($t['kind'] === 'KEYWORD' && $t['value'] === 'NULL') { $this->next(); return null; }
        $this->error("Expected a value, got \"{$t['value']}\"");
        return null; // unreachable
    }

    private function parseOrderTerm(): array
    {
        $term = ['ref' => $this->expectIdent()];
        if ($this->atKeyword('ASC')) { $this->next(); $term['direction'] = 'ASC'; }
        elseif ($this->atKeyword('DESC')) { $this->next(); $term['direction'] = 'DESC'; }
        if ($this->atKeyword('NUMERIC')) { $this->next(); $term['numeric'] = true; }
        return $term;
    }

    private function parseSelect(): array
    {
        $select = [];
        if ($this->peek()['kind'] === 'STAR') {
            $this->next();
        } else {
            $select['fields'] = [$this->expectIdent()];
            while ($this->peek()['kind'] === 'COMMA') {
                $this->next();
                $select['fields'][] = $this->expectIdent();
            }
        }
        if ($this->atKeyword('WITH')) {
            $this->next();
            $this->expectKeyword('STORAGE');
            $select['storage'] = true;
        }
        return $select;
    }
}
