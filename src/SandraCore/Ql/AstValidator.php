<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/**
 * Structural validator for the SandraQL v1.0 AST — port of validate.ts,
 * semantically equivalent to resources/sandraql.schema.json.
 */
class AstValidator
{
    private const REF_OPS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN'];

    /** Validate an AST (assoc array, e.g. json_decode(..., true)). Returns it. */
    public static function validate(mixed $ast): array
    {
        if (!self::isMap($ast)) {
            self::fail('AST must be an object', '$');
        }
        self::assertKeys($ast, ['sandraql', 'type', 'match', 'where', 'order', 'limit', 'offset', 'select'], '$');
        if (($ast['sandraql'] ?? null) !== '1.0') {
            self::fail('Unsupported sandraql version "' . json_encode($ast['sandraql'] ?? null) . '"', '$.sandraql');
        }
        if (($ast['type'] ?? null) !== 'query') {
            self::fail('Unsupported type (only "query" in v1)', '$.type');
        }

        if (!self::isMap($ast['match'] ?? null)) {
            self::fail('"match" must be an object', '$.match');
        }
        self::assertKeys($ast['match'], ['isa', 'file'], '$.match');
        self::validateIdent($ast['match']['isa'] ?? null, '$.match.isa');
        if (array_key_exists('file', $ast['match'])) {
            self::validateIdent($ast['match']['file'], '$.match.file');
        }

        if (array_key_exists('where', $ast)) {
            self::validateWhereNode($ast['where'], '$.where');
        }

        if (array_key_exists('order', $ast)) {
            if (!is_array($ast['order']) || self::isMap($ast['order']) || count($ast['order']) < 1) {
                self::fail('"order" must be a non-empty array', '$.order');
            }
            foreach ($ast['order'] as $i => $term) {
                $p = "\$.order[$i]";
                if (!self::isMap($term)) {
                    self::fail('Order term must be an object', $p);
                }
                self::assertKeys($term, ['ref', 'direction', 'numeric'], $p);
                self::validateIdent($term['ref'] ?? null, "$p.ref");
                if (array_key_exists('direction', $term) && !in_array($term['direction'], ['ASC', 'DESC'], true)) {
                    self::fail('direction must be "ASC" or "DESC"', "$p.direction");
                }
                if (array_key_exists('numeric', $term) && !is_bool($term['numeric'])) {
                    self::fail('numeric must be a boolean', "$p.numeric");
                }
            }
        }

        foreach (['limit', 'offset'] as $k) {
            if (array_key_exists($k, $ast)) {
                $v = $ast[$k];
                if (!is_int($v) || $v < 0) {
                    self::fail("\"$k\" must be a non-negative integer", "\$.$k");
                }
            }
        }

        if (array_key_exists('select', $ast)) {
            if (!self::isMap($ast['select'])) {
                self::fail('"select" must be an object', '$.select');
            }
            self::assertKeys($ast['select'], ['fields', 'storage'], '$.select');
            if (array_key_exists('fields', $ast['select'])) {
                if (!is_array($ast['select']['fields']) || self::isMap($ast['select']['fields'])) {
                    self::fail('"select.fields" must be an array', '$.select.fields');
                }
                foreach ($ast['select']['fields'] as $i => $f) {
                    if (!is_string($f)) {
                        self::fail('Field names must be strings', "\$.select.fields[$i]");
                    }
                }
            }
            if (array_key_exists('storage', $ast['select']) && !is_bool($ast['select']['storage'])) {
                self::fail('"select.storage" must be a boolean', '$.select.storage');
            }
        }

        return $ast;
    }

    private static function validateWhereNode(mixed $node, string $path): void
    {
        if (!self::isMap($node)) {
            self::fail('Expected a where node object', $path);
        }

        if (array_key_exists('and', $node) || array_key_exists('or', $node)) {
            $kind = array_key_exists('and', $node) ? 'and' : 'or';
            self::assertKeys($node, [$kind], $path);
            $arr = $node[$kind];
            if (!is_array($arr) || self::isMap($arr) || count($arr) < 1) {
                self::fail("\"$kind\" must be a non-empty array", $path);
            }
            foreach ($arr as $i => $child) {
                self::validateWhereNode($child, "$path.{$kind}[$i]");
            }
            return;
        }
        if (array_key_exists('not', $node)) {
            self::assertKeys($node, ['not'], $path);
            self::validateWhereNode($node['not'], "$path.not");
            return;
        }
        if (array_key_exists('ref', $node)) {
            self::assertKeys($node, ['ref', 'op', 'value'], $path);
            self::validateIdent($node['ref'], "$path.ref");
            $op = $node['op'] ?? null;
            if (!is_string($op) || !in_array($op, self::REF_OPS, true)) {
                self::fail('Invalid operator (allowed: ' . implode(' ', self::REF_OPS) . ')', "$path.op");
            }
            if (!array_key_exists('value', $node)) {
                self::fail('Missing "value"', $path);
            }
            $value = $node['value'];
            if ($op === 'IN') {
                if (!is_array($value) || self::isMap($value) || count($value) < 1) {
                    self::fail('IN requires a non-empty array value', "$path.value");
                }
                foreach ($value as $i => $v) {
                    if (!self::isScalarValue($v)) {
                        self::fail('IN values must be scalars', "$path.value[$i]");
                    }
                }
            } elseif (is_array($value)) {
                self::fail("Operator \"$op\" requires a scalar value, not an array", "$path.value");
            } elseif (!self::isScalarValue($value)) {
                self::fail('Value must be a scalar (string, number, boolean, null)', "$path.value");
            }
            return;
        }
        if (array_key_exists('has', $node)) {
            self::assertKeys($node, ['has'], $path);
            $has = $node['has'];
            if (!self::isMap($has)) {
                self::fail('"has" must be an object', "$path.has");
            }
            self::assertKeys($has, ['verb', 'target'], "$path.has");
            if (!array_key_exists('verb', $has) && !array_key_exists('target', $has)) {
                self::fail('"has" requires verb and/or target', "$path.has");
            }
            foreach (['verb', 'target'] as $k) {
                if (array_key_exists($k, $has) && !is_string($has[$k]) && !is_int($has[$k]) && !is_float($has[$k])) {
                    self::fail('Expected a concept shortname (string) or id (number)', "$path.has.$k");
                }
            }
            return;
        }
        self::fail('Unrecognized where node (keys: ' . implode(', ', array_keys($node)) . ')', $path);
    }

    private static function isMap(mixed $v): bool
    {
        return is_array($v) && ($v === [] ? false : array_keys($v) !== range(0, count($v) - 1));
    }

    private static function isScalarValue(mixed $v): bool
    {
        return $v === null || is_string($v) || is_int($v) || is_float($v) || is_bool($v);
    }

    private static function validateIdent(mixed $v, string $path): void
    {
        if (!is_string($v) || strlen($v) < 1 || strlen($v) > 64) {
            self::fail('Expected an identifier string (1-64 chars)', $path);
        }
    }

    private static function assertKeys(array $obj, array $allowed, string $path): void
    {
        foreach (array_keys($obj) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                self::fail("Unknown property \"$k\"", $path);
            }
        }
    }

    private static function fail(string $message, string $path): void
    {
        throw new SandraQlValidationException($message, $path);
    }
}
