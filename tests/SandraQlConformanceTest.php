<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Ql\AstExecutor;

/**
 * Cross-library conformance — the PHP side of the "unified query language"
 * guarantee. Seeds tests/conformance/seed.json, runs every query in
 * queries.json (AST and SandraQL text) and compares ref-value rows.
 * The JS repo (sandra-js) runs the exact same fixtures in
 * packages/core/test/conformance.spec.ts.
 *
 * Queries tagged `requires: ["or"]` are skipped: the PHP executor is
 * AND-only in this phase (UnsupportedAstFeatureException) while the JS
 * executor compiles OR via EXISTS.
 */
class SandraQlConformanceTest extends SandraTestCase
{
    private array $queries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = json_decode(
            (string) file_get_contents(__DIR__ . '/conformance/queries.json'),
            true
        );
        $this->seedDatagraph();
    }

    private function seedDatagraph(): void
    {
        $seed = json_decode((string) file_get_contents(__DIR__ . '/conformance/seed.json'), true);

        $files = [];
        foreach ($seed['factories'] as $f) {
            $files[$f['isa']] = $f['file'];
        }

        $byKey = [];
        foreach ($seed['entities'] as $spec) {
            $factory = new EntityFactory($spec['factory'], $files[$spec['factory']], $this->system);
            $entity = $factory->createNew($spec['refs']);
            if (!empty($spec['storage'])) {
                $entity->setStorage($spec['storage']);
            }
            foreach ($spec['brothers'] ?? [] as $brother) {
                $entity->setBrotherEntity($brother['verb'], $brother['target'], []);
            }
            if (!empty($spec['deleted'])) {
                $entity->delete();
            }
            $byKey[$spec['key']] = $entity;
        }

        foreach ($seed['joined'] ?? [] as $join) {
            $byKey[$join['from']]->setJoinedEntity($join['verb'], $byKey[$join['to']], $join['refs'] ?? []);
        }
    }

    public function testConformanceQueries(): void
    {
        $executor = new AstExecutor($this->system);
        $ran = 0;
        $skipped = [];

        foreach ($this->queries as $q) {
            if (!empty($q['requires'])) {
                $skipped[] = $q['name'];
                continue;
            }

            foreach (['ast', 'sandraql'] as $mode) {
                $entities = $mode === 'ast'
                    ? $executor->execute($q['ast'])
                    : $executor->ql($q['sandraql']);

                $rows = $this->projectRows(array_values($entities), $q['expect']['rows']);

                if ($q['expect']['order'] === 'strict') {
                    $this->assertSame(
                        json_encode($q['expect']['rows']),
                        json_encode($rows),
                        "[{$q['name']}] ($mode) strict order mismatch"
                    );
                } else {
                    $this->assertSameSize($q['expect']['rows'], $rows, "[{$q['name']}] ($mode) row count");
                    foreach ($q['expect']['rows'] as $expected) {
                        $this->assertContains($expected, $rows, "[{$q['name']}] ($mode) missing row");
                    }
                }

                if (!empty($q['expect']['exactFields'])) {
                    $allowed = [];
                    foreach ($q['expect']['rows'] as $row) {
                        foreach (array_keys($row) as $k) {
                            $allowed[$k] = true;
                        }
                    }
                    $serialized = AstExecutor::serialize(array_values($entities), $q['ast']);
                    foreach ($serialized as $row) {
                        foreach (array_keys($row['refs']) as $k) {
                            $this->assertArrayHasKey($k, $allowed, "[{$q['name']}] projection leaked field $k");
                        }
                    }
                }
            }
            $ran++;
        }

        $this->assertGreaterThan(8, $ran, 'Too few conformance queries ran');
        fwrite(STDERR, sprintf(
            "\nSandraQL conformance: %d queries × 2 modes passed, %d skipped (requires: or) [%s]\n",
            $ran,
            count($skipped),
            implode(', ', $skipped)
        ));
    }

    /**
     * Project entities to rows containing only the keys used in expectations
     * (ids differ across seeders, so expectations use ref values only).
     */
    private function projectRows(array $entities, array $expectedRows): array
    {
        $keys = [];
        foreach ($expectedRows as $row) {
            foreach (array_keys($row) as $k) {
                $keys[$k] = true;
            }
        }
        $out = [];
        foreach ($entities as $entity) {
            $row = [];
            foreach (array_keys($keys) as $key) {
                $value = $entity->get($key);
                if ($value !== null) {
                    $row[$key] = (string) $value;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
