<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\EntityFactory;
use SandraCore\Ql\AstExecutor;
use SandraCore\Ql\Parser;

/**
 * ACL conformance — PHP side of tests/conformance/acl.json (synced from
 * sandra-js). Seeds seed.json + principals/roles/grants, then runs each
 * scenario as its actor and compares rows with the JS runner
 * (packages/core/test/acl-conformance.spec.ts).
 */
class SandraQlAclConformanceTest extends SandraTestCase
{
    /** @var array<string, int> principal key -> concept id */
    private array $principalIds = [];

    /** @var array<string, \SandraCore\Entity> seed key -> entity, for the facets */
    private array $entitiesByKey = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->seedAcl();
    }

    private function seedBase(): void
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
            $this->entitiesByKey[$spec['key']] = $entity;
        }
        foreach ($seed['joined'] ?? [] as $join) {
            $byKey[$join['from']]->setJoinedEntity($join['verb'], $byKey[$join['to']], $join['refs'] ?? []);
        }
    }

    private function seedAcl(): void
    {
        $acl = json_decode((string) file_get_contents(__DIR__ . '/conformance/acl.json'), true);
        $sc = $this->system->systemConcept;

        foreach ($acl['principals'] as $p) {
            $factory = new EntityFactory($p['factory'], $p['file'], $this->system);
            $entity = $factory->createNew($p['refs']);
            $this->principalIds[$p['key']] = (int) $entity->subjectConcept->idConcept;
        }
        foreach ($acl['roles'] as $r) {
            $subjectId = $this->principalIds[$r['principal']];
            \SandraCore\DatabaseAdapter::rawCreateTriplet(
                $subjectId,
                (int) $sc->get(AclResolver::HAS_ROLE),
                (int) $sc->get($r['role']),
                $this->system
            );
        }
        // Facets: file an ALREADY SEEDED concept under a second file, with its
        // own refs. Refs hang on the contained_in_file triplet, so each file
        // carries a disjoint set for the same concept.
        foreach ($acl['facets'] ?? [] as $facet) {
            $concept = (int) $this->entitiesByKey[$facet['entity']]->subjectConcept->idConcept;
            $link = (int) \SandraCore\DatabaseAdapter::rawCreateTriplet(
                $concept,
                (int) $sc->get('contained_in_file'),
                (int) $sc->get($facet['file']),
                $this->system
            );
            foreach ($facet['refs'] as $key => $value) {
                \SandraCore\DatabaseAdapter::rawCreateReference($link, (int) $sc->get((string) $key), (string) $value, $this->system);
            }
        }

        foreach ($acl['grants'] as $g) {
            $subjectId = $this->principalIds[$g['subject']] ?? (int) $sc->get($g['subject']);
            \SandraCore\DatabaseAdapter::rawCreateTriplet(
                $subjectId,
                (int) $sc->get(AclResolver::ALLOW_ACCESS),
                (int) $sc->get($g['file']),
                $this->system
            );
            if (!empty($g['write'])) {
                \SandraCore\DatabaseAdapter::rawCreateTriplet(
                    $subjectId,
                    (int) $sc->get(AclResolver::ALLOW_WRITE),
                    (int) $sc->get($g['file']),
                    $this->system
                );
            }
        }
    }

    public function testAclScenarios(): void
    {
        $acl = json_decode((string) file_get_contents(__DIR__ . '/conformance/acl.json'), true);

        foreach ($acl['scenarios'] as $scenario) {
            $executor = (new AstExecutor($this->system))
                ->asPrincipal($this->principalIds[$scenario['actor']]);
            $entities = $executor->execute(Parser::parse($scenario['sandraql']));

            $keys = [];
            foreach ($scenario['expect']['rows'] as $row) {
                foreach (array_keys($row) as $k) {
                    $keys[$k] = true;
                }
            }
            $rows = [];
            foreach ($entities as $entity) {
                $row = [];
                foreach (array_keys($keys) as $key) {
                    $value = $entity->get($key);
                    if ($value !== null) {
                        $row[$key] = (string) $value;
                    }
                }
                $rows[] = $row;
            }

            if ($scenario['expect']['order'] === 'strict') {
                $this->assertSame(
                    json_encode($scenario['expect']['rows']),
                    json_encode(array_values($rows)),
                    "[{$scenario['name']}] strict order mismatch"
                );
            } else {
                $this->assertSameSize($scenario['expect']['rows'], $rows, "[{$scenario['name']}] row count");
                foreach ($scenario['expect']['rows'] as $expected) {
                    $this->assertContains($expected, $rows, "[{$scenario['name']}] missing row");
                }
            }
        }

        $this->assertGreaterThan(3, count($acl['scenarios']));
    }

    public function testResolverRolesAndWildcard(): void
    {
        $rootId = $this->principalIds['root_key'];
        $ctx = AclResolver::resolve($this->system, $rootId);
        $this->assertTrue($ctx->readAll);
        $this->assertTrue($ctx->writeAll);
        $this->assertTrue($ctx->isAdmin());

        $aliceId = $this->principalIds['alice_key'];
        $aliceCtx = AclResolver::resolve($this->system, $aliceId);
        $this->assertFalse($aliceCtx->readAll);
        $personFileId = (int) $this->system->systemConcept->get('person_file', null, false);
        $this->assertTrue($aliceCtx->canRead($personFileId));
        $this->assertFalse($aliceCtx->canWrite($personFileId));
    }
}
