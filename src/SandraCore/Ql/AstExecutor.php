<?php
declare(strict_types=1);

namespace SandraCore\Ql;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\AclResolver;
use SandraCore\DatabaseAdapter;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\System;

/**
 * Executes a SandraQL v1.0 AST against a Sandra datagraph, reusing the
 * existing SQL machinery:
 *
 *  - ref-only AND queries   → EntityFactory::populateFromRefQuery
 *                             (full SQL pushdown incl. numeric sort + pagination)
 *  - HAS-only queries       → FactoryBase::setFilter + populateLocal
 *  - combined ref + HAS     → brother filters at SQL + ref query at SQL,
 *                             intersected on concept ids (ref-SQL order kept)
 *
 * OR trees and NOT on ref leaves raise UnsupportedAstFeatureException —
 * the JS library (@everdreamsoft/sandra) executes those via EXISTS
 * compilation; the same is planned here (searchConceptByAstQuery).
 */
class AstExecutor
{
    private System $system;
    private ?AccessContext $access = null;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    /**
     * Scope every execution to an acting principal (graph-native ACL).
     * Default-deny: only files granted via sandra_allow_access are readable.
     * Enforcement is a single pre-flight check on the query's anchor file —
     * zero SQL overhead.
     */
    public function withAccess(?AccessContext $access): self
    {
        $this->access = $access;
        return $this;
    }

    /** Convenience: resolve a principal's grants and scope the executor. */
    public function asPrincipal(int|string $principal): self
    {
        return $this->withAccess(AclResolver::resolve($this->system, $principal));
    }

    /**
     * Parse and execute SandraQL text.
     *
     * @return Entity[] keyed by concept id, in query order
     */
    public function ql(string $text): array
    {
        return $this->execute(Parser::parse($text));
    }

    /**
     * Execute a validated AST.
     *
     * @param array $ast SandraQL v1.0 query AST (assoc array)
     * @return Entity[] keyed by concept id, in query order
     */
    public function execute(array $ast): array
    {
        AstValidator::validate($ast);

        $isa = $ast['match']['isa'];
        $file = $ast['match']['file'] ?? ($isa . '_file');

        // ACL pre-flight: unreadable (or unknown-to-this-principal) file → empty
        if ($this->access !== null) {
            $fileId = $this->system->systemConcept->get($file, null, false);
            if (!is_numeric($fileId) || !$this->access->canRead((int) $fileId)) {
                return [];
            }
        }

        $limit = $ast['limit'] ?? null;
        $offset = $ast['offset'] ?? null;
        $sort = null;
        if (!empty($ast['order'])) {
            if (count($ast['order']) > 1) {
                throw new UnsupportedAstFeatureException('multi-term ORDER BY (PHP executor supports one term)');
            }
            $term = $ast['order'][0];
            $sort = [
                'ref' => $term['ref'],
                'direction' => $term['direction'] ?? 'ASC',
                'numeric' => $term['numeric'] ?? false,
            ];
        }

        [$refFilters, $brotherFilters] = $this->flattenWhere($ast['where'] ?? null);

        $factory = new EntityFactory($isa, $file, $this->system);

        // HAS filters go to SQL joins on every path
        foreach ($brotherFilters as $bf) {
            $factory->setFilter($bf['verb'] ?? 0, $bf['target'] ?? 0, $bf['exclude']);
        }

        if (empty($refFilters)) {
            // brothers-only (or unfiltered). Note: populateLocal's own sort
            // path loses the DESC direction (getReferences has no direction
            // parameter), so when a sort is requested we populate unsorted
            // and order deterministically in PHP.
            if ($sort === null) {
                $factory->populateLocal($limit, $offset ?? 0);
                return $factory->getEntities() ?: [];
            }
            $factory->populateLocal();
            $entities = $factory->getEntities() ?: [];
            $entities = self::sortEntities($entities, $sort);
            return self::paginate($entities, $offset, $limit);
        }

        if (empty($brotherFilters)) {
            // ref-only: full pushdown
            $entities = $factory->populateFromRefQuery($refFilters, $sort, $limit, $offset);
            return $entities;
        }

        // combined: ref query at SQL (keeps sort order), brother filters at SQL,
        // intersect concept ids — pagination applied after the intersection
        $refConceptIds = DatabaseAdapter::searchConceptByRefQuery(
            $this->system,
            $refFilters,
            $factory->entityReferenceContainer,
            $factory->entityContainedIn,
            $sort,
            null,
            null
        ) ?: [];
        if (empty($refConceptIds)) {
            return [];
        }

        $factory->populateLocal(); // brother filters applied by ConceptManager
        $brotherMatched = $factory->getEntities() ?: [];

        $ordered = [];
        foreach ($refConceptIds as $cid) {
            if (isset($brotherMatched[$cid])) {
                $ordered[$cid] = $brotherMatched[$cid];
            }
        }
        if ($offset !== null && $offset > 0) {
            $ordered = array_slice($ordered, $offset, null, true);
        }
        if ($limit !== null) {
            $ordered = array_slice($ordered, 0, $limit, true);
        }
        return $ordered;
    }

    /**
     * Stable sort of entities on a reference value (numeric-aware, keys kept).
     *
     * @param Entity[] $entities
     * @return Entity[]
     */
    private static function sortEntities(array $entities, array $sort): array
    {
        $ref = $sort['ref'];
        $desc = ($sort['direction'] ?? 'ASC') === 'DESC';
        $numeric = (bool) ($sort['numeric'] ?? false);

        uasort($entities, static function (Entity $a, Entity $b) use ($ref, $desc, $numeric): int {
            $va = $a->get($ref);
            $vb = $b->get($ref);
            // entities missing the ref sort last, both directions (SQL LEFT JOIN NULL behavior)
            if ($va === null && $vb === null) return 0;
            if ($va === null) return 1;
            if ($vb === null) return -1;
            $cmp = $numeric ? ((float) $va <=> (float) $vb) : strcmp((string) $va, (string) $vb);
            return $desc ? -$cmp : $cmp;
        });
        return $entities;
    }

    /**
     * @param Entity[] $entities
     * @return Entity[]
     */
    private static function paginate(array $entities, ?int $offset, ?int $limit): array
    {
        if ($offset !== null && $offset > 0) {
            $entities = array_slice($entities, $offset, null, true);
        }
        if ($limit !== null) {
            $entities = array_slice($entities, 0, $limit, true);
        }
        return $entities;
    }

    /**
     * Split a conjunctive where tree into ref filters and brother filters.
     *
     * @return array{0: array<int, array{ref:string, op:string, value:mixed}>,
     *               1: array<int, array{verb?:mixed, target?:mixed, exclude:bool}>}
     */
    private function flattenWhere(?array $where): array
    {
        if ($where === null) {
            return [[], []];
        }
        $leaves = array_key_exists('and', $where) ? $where['and'] : [$where];

        $refFilters = [];
        $brotherFilters = [];
        foreach ($leaves as $leaf) {
            if (array_key_exists('or', $leaf)) {
                throw new UnsupportedAstFeatureException('or');
            }
            if (array_key_exists('and', $leaf)) {
                throw new UnsupportedAstFeatureException('nested and');
            }
            if (array_key_exists('not', $leaf)) {
                $inner = $leaf['not'];
                if (is_array($inner) && array_key_exists('has', $inner)) {
                    $brotherFilters[] = [
                        'verb' => $inner['has']['verb'] ?? 0,
                        'target' => $inner['has']['target'] ?? 0,
                        'exclude' => true,
                    ];
                    continue;
                }
                throw new UnsupportedAstFeatureException('not (only NOT HAS is supported)');
            }
            if (array_key_exists('has', $leaf)) {
                $brotherFilters[] = [
                    'verb' => $leaf['has']['verb'] ?? 0,
                    'target' => $leaf['has']['target'] ?? 0,
                    'exclude' => false,
                ];
                continue;
            }
            if (array_key_exists('ref', $leaf)) {
                $refFilters[] = ['ref' => $leaf['ref'], 'op' => $leaf['op'], 'value' => $leaf['value']];
                continue;
            }
            throw new UnsupportedAstFeatureException('unknown where node');
        }
        return [$refFilters, $brotherFilters];
    }

    /**
     * Serialize executed entities honoring the AST's select clause.
     *
     * @param Entity[] $entities
     */
    public static function serialize(array $entities, array $ast): array
    {
        $fields = $ast['select']['fields'] ?? null;
        if ($fields !== null && in_array('*', $fields, true)) {
            $fields = null;
        }
        $includeStorage = (bool) ($ast['select']['storage'] ?? false);

        $out = [];
        foreach ($entities as $entity) {
            $refs = [];
            foreach ($entity->entityRefs ?? [] as $reference) {
                $name = $reference->refConcept->getShortname();
                if ($name === null || $name === '') {
                    continue;
                }
                if ($fields !== null && !in_array($name, $fields, true)) {
                    continue;
                }
                $refs[$name] = $reference->refValue;
            }
            $row = [
                'id' => (int) $entity->entityId,
                'conceptId' => (int) $entity->subjectConcept->idConcept,
                'refs' => $refs,
            ];
            if ($includeStorage) {
                $storage = $entity->getStorage();
                if ($storage !== null) {
                    $row['storage'] = $storage;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
