<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\Ql\Lexer;
use SandraCore\Ql\Parser;
use SandraCore\Ql\AstValidator;
use SandraCore\Ql\SandraQlSyntaxException;
use SandraCore\Ql\SandraQlValidationException;

/**
 * SandraQL parser tests. The goldens in tests/conformance/parser.json are
 * shared byte-for-byte with the JS parser (sandra-js) — both parsers must
 * produce the identical AST.
 */
class SandraQlParserTest extends TestCase
{
    /** Shared parser goldens: PHP parse() must equal the canonical AST. */
    public function testSharedParserGoldens(): void
    {
        $goldens = json_decode(
            (string) file_get_contents(__DIR__ . '/conformance/parser.json'),
            true
        );
        $this->assertNotEmpty($goldens);
        foreach ($goldens as $golden) {
            $ast = Parser::parse($golden['sandraql']);
            // compare via canonical JSON so int/float and key order normalize
            $this->assertSame(
                json_encode($golden['ast']),
                json_encode($ast),
                "Golden mismatch: {$golden['name']}"
            );
            AstValidator::validate($ast);
        }
    }

    public function testFixtureManifestHashesMatch(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/conformance/manifest.json'), true);
        $this->assertNotEmpty($manifest);
        foreach ($manifest as $relPath => $expectedHash) {
            $full = __DIR__ . '/../' . $relPath;
            $this->assertFileExists($full, "Missing synced fixture $relPath");
            $this->assertSame(
                $expectedHash,
                hash('sha256', (string) file_get_contents($full)),
                "Fixture drift detected in $relPath — re-run `pnpm sync:conformance` in sandra-js"
            );
        }
    }

    public function testFullClauseSet(): void
    {
        $ast = Parser::parse(
            'MATCH person IN people_file ' .
            'WHERE age >= 18 AND (city = "Geneva" OR city = "Lausanne") ' .
            'AND HAS friend -> alice AND NOT HAS status -> banned ' .
            'ORDER BY age DESC NUMERIC, name ASC LIMIT 10 OFFSET 20 SELECT name, age WITH STORAGE'
        );
        $this->assertSame(['isa' => 'person', 'file' => 'people_file'], $ast['match']);
        $this->assertSame(10, $ast['limit']);
        $this->assertSame(20, $ast['offset']);
        $this->assertSame(['fields' => ['name', 'age'], 'storage' => true], $ast['select']);
        $this->assertCount(4, $ast['where']['and']);
        $this->assertSame(['ref' => 'age', 'op' => '>=', 'value' => 18], $ast['where']['and'][0]);
        $this->assertArrayHasKey('or', $ast['where']['and'][1]);
        $this->assertSame(['has' => ['verb' => 'friend', 'target' => 'alice']], $ast['where']['and'][2]);
        $this->assertSame(['not' => ['has' => ['verb' => 'status', 'target' => 'banned']]], $ast['where']['and'][3]);
    }

    public function testValuesAndOperators(): void
    {
        $ast = Parser::parse(
            'MATCH p WHERE tier IN ("gold", 3) AND name LIKE "al%" AND active = TRUE AND note = NULL AND delta > -1.5 AND x <> 2'
        );
        $and = $ast['where']['and'];
        $this->assertSame(['ref' => 'tier', 'op' => 'IN', 'value' => ['gold', 3]], $and[0]);
        $this->assertSame(['ref' => 'name', 'op' => 'LIKE', 'value' => 'al%'], $and[1]);
        $this->assertSame(['ref' => 'active', 'op' => '=', 'value' => true], $and[2]);
        $this->assertSame(['ref' => 'note', 'op' => '=', 'value' => null], $and[3]);
        $this->assertSame(['ref' => 'delta', 'op' => '>', 'value' => -1.5], $and[4]);
        $this->assertSame(['ref' => 'x', 'op' => '!=', 'value' => 2], $and[5]);
    }

    public function testSyntaxErrorsCarryPosition(): void
    {
        try {
            Parser::parse('MATCH person WHERE age >');
            $this->fail('Expected SandraQlSyntaxException');
        } catch (SandraQlSyntaxException $e) {
            $this->assertSame(1, $e->sourceLine);
        }

        $this->expectException(SandraQlSyntaxException::class);
        Parser::parse('MATCH person LIMIT 5 nonsense');
    }

    public function testValidatorRejectsBadAst(): void
    {
        $this->expectException(SandraQlValidationException::class);
        AstValidator::validate(['sandraql' => '1.0', 'type' => 'query', 'match' => ['isa' => 'p'], 'bogus' => 1]);
    }

    public function testLexerKeywordsCaseInsensitive(): void
    {
        $tokens = Lexer::tokenize("match p -- comment\nwhere a = 1 # tail");
        $kinds = array_column($tokens, 'kind');
        $this->assertSame(['KEYWORD', 'IDENT', 'KEYWORD', 'IDENT', 'OP', 'NUMBER', 'EOF'], $kinds);
    }
}
