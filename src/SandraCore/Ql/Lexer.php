<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/**
 * SandraQL lexer — 1:1 port of packages/sandraql/src/lexer.ts from sandra-js.
 * Same token kinds and keyword set; verified by the shared parser.json goldens.
 *
 * Token: ['kind' => string, 'value' => string, 'line' => int, 'column' => int]
 * Kinds: KEYWORD IDENT NUMBER STRING OP ARROW LPAREN RPAREN COMMA STAR EOF
 */
class Lexer
{
    public const KEYWORDS = [
        'MATCH', 'IN', 'WHERE', 'AND', 'OR', 'NOT', 'HAS',
        'ORDER', 'BY', 'ASC', 'DESC', 'NUMERIC',
        'LIMIT', 'OFFSET', 'SELECT', 'WITH', 'STORAGE',
        'LIKE', 'TRUE', 'FALSE', 'NULL',
        'TRAVERSE', // reserved for v2
    ];

    /**
     * @return array<int, array{kind:string, value:string, line:int, column:int}>
     */
    public static function tokenize(string $input): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($input);
        $line = 1;
        $lineStart = 0;

        $col = static function (int $at) use (&$lineStart): int {
            return $at - $lineStart + 1;
        };

        while ($i < $len) {
            $c = $input[$i];

            if ($c === "\n") {
                $line++;
                $i++;
                $lineStart = $i;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $i++;
                continue;
            }
            // comments: -- to end of line, # to end of line
            if (($c === '-' && ($input[$i + 1] ?? '') === '-') || $c === '#') {
                while ($i < $len && $input[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            $start = $i;

            if ($c === '(') { $tokens[] = ['kind' => 'LPAREN', 'value' => '(', 'line' => $line, 'column' => $col($start)]; $i++; continue; }
            if ($c === ')') { $tokens[] = ['kind' => 'RPAREN', 'value' => ')', 'line' => $line, 'column' => $col($start)]; $i++; continue; }
            if ($c === ',') { $tokens[] = ['kind' => 'COMMA', 'value' => ',', 'line' => $line, 'column' => $col($start)]; $i++; continue; }
            if ($c === '*') { $tokens[] = ['kind' => 'STAR', 'value' => '*', 'line' => $line, 'column' => $col($start)]; $i++; continue; }

            if ($c === '-' && ($input[$i + 1] ?? '') === '>') {
                $tokens[] = ['kind' => 'ARROW', 'value' => '->', 'line' => $line, 'column' => $col($start)];
                $i += 2;
                continue;
            }

            if ($c === '=') { $tokens[] = ['kind' => 'OP', 'value' => '=', 'line' => $line, 'column' => $col($start)]; $i++; continue; }
            if ($c === '!') {
                if (($input[$i + 1] ?? '') === '=') {
                    $tokens[] = ['kind' => 'OP', 'value' => '!=', 'line' => $line, 'column' => $col($start)];
                    $i += 2;
                    continue;
                }
                throw new SandraQlSyntaxException('Unexpected "!"', $line, $col($start));
            }
            if ($c === '>' || $c === '<') {
                if (($input[$i + 1] ?? '') === '=') {
                    $tokens[] = ['kind' => 'OP', 'value' => $c . '=', 'line' => $line, 'column' => $col($start)];
                    $i += 2;
                } elseif ($c === '<' && ($input[$i + 1] ?? '') === '>') {
                    $tokens[] = ['kind' => 'OP', 'value' => '!=', 'line' => $line, 'column' => $col($start)];
                    $i += 2;
                } else {
                    $tokens[] = ['kind' => 'OP', 'value' => $c, 'line' => $line, 'column' => $col($start)];
                    $i++;
                }
                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;
                $out = '';
                $closed = false;
                while ($i < $len) {
                    $ch = $input[$i];
                    if ($ch === '\\') {
                        $next = $input[$i + 1] ?? null;
                        if ($next === null) {
                            throw new SandraQlSyntaxException('Unterminated escape sequence', $line, $col($i));
                        }
                        if ($next === 'n') $out .= "\n";
                        elseif ($next === 't') $out .= "\t";
                        elseif ($next === 'r') $out .= "\r";
                        else $out .= $next;
                        $i += 2;
                        continue;
                    }
                    if ($ch === "\n") {
                        throw new SandraQlSyntaxException('Unterminated string literal', $line, $col($start));
                    }
                    if ($ch === $quote) { $closed = true; $i++; break; }
                    $out .= $ch;
                    $i++;
                }
                if (!$closed) {
                    throw new SandraQlSyntaxException('Unterminated string literal', $line, $col($start));
                }
                $tokens[] = ['kind' => 'STRING', 'value' => $out, 'line' => $line, 'column' => $col($start)];
                continue;
            }

            $isDigit = static fn (string $ch): bool => $ch >= '0' && $ch <= '9';

            if ($isDigit($c) || ($c === '-' && $isDigit($input[$i + 1] ?? 'x'))) {
                $num = '';
                if ($c === '-') { $num .= '-'; $i++; }
                while ($i < $len && $isDigit($input[$i])) { $num .= $input[$i]; $i++; }
                if (($input[$i] ?? '') === '.' && $isDigit($input[$i + 1] ?? 'x')) {
                    $num .= '.';
                    $i++;
                    while ($i < $len && $isDigit($input[$i])) { $num .= $input[$i]; $i++; }
                }
                $tokens[] = ['kind' => 'NUMBER', 'value' => $num, 'line' => $line, 'column' => $col($start)];
                continue;
            }

            if (preg_match('/[A-Za-z_]/', $c)) {
                $word = '';
                while ($i < $len && preg_match('/[A-Za-z0-9_]/', $input[$i])) { $word .= $input[$i]; $i++; }
                $upper = strtoupper($word);
                if (in_array($upper, self::KEYWORDS, true)) {
                    $tokens[] = ['kind' => 'KEYWORD', 'value' => $upper, 'line' => $line, 'column' => $col($start)];
                } else {
                    $tokens[] = ['kind' => 'IDENT', 'value' => $word, 'line' => $line, 'column' => $col($start)];
                }
                continue;
            }

            throw new SandraQlSyntaxException("Unexpected character \"$c\"", $line, $col($start));
        }

        $tokens[] = ['kind' => 'EOF', 'value' => '', 'line' => $line, 'column' => $col($i)];
        return $tokens;
    }
}
