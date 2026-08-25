<?php
declare(strict_types=1);

namespace SandraCore\Acl;

use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Endpoint-derived visibility of triplets.
 *
 * Sandra's ACL unit is the `contained_in_file` concept: a grant makes the
 * ENTITIES of a file readable. A triplet is not itself contained in a file, so
 * file grants alone leave the LINKS visible — and the link is often the secret.
 * "Address X is memberOf group #4711" discloses the clustering even when the
 * group entity cannot be read.
 *
 * The rule implemented here:
 *
 *   A triplet is visible iff EVERY endpoint that has a `contained_in_file`
 *   points at a readable file. An endpoint with no `contained_in_file` — a bare
 *   system concept, i.e. vocabulary — is always visible.
 *
 * Both endpoints, because the secret sits on either side depending on the
 * shape: `annotation --about--> address` hides on the subject,
 * `address --memberOf--> group` on the target.
 *
 * A corollary worth knowing: this makes a bare CONCEPT protectable too. Give
 * the concept its own `contained_in_file` triplet and every link touching it
 * follows that file's grants — which is how a private tag stays private.
 * FactoryDiscovery requires is_a AND contained_in_file to recognise an entity,
 * so a concept carrying only a file does not become a phantom entity.
 *
 * The filter is a SQL fragment, never a post-pass in PHP: LIMIT, COUNT and
 * NOT HAS must be evaluated on the filtered graph, otherwise the negative
 * answer leaks what the positive one hides. For the principal the triplet does
 * not exist — this is a view, not a mask.
 *
 * Cost: one correlated NOT EXISTS per endpoint column, served by the unique key
 * (idConceptStart, idConceptLink, idConceptTarget) — an index lookup per row,
 * with no set to materialise and a parameter count bounded by the grant list.
 */
final class TripletVisibility
{
    /** @param int[] $allowedFiles */
    private function __construct(
        private readonly System $system,
        private readonly int $cifId,
        private readonly int $deletedId,
        private readonly string $linkTable,
        private readonly array $allowedFiles,
    ) {
    }

    /**
     * Null when nothing can be hidden — no principal, a read-everything grant,
     * or a graph with no `contained_in_file` concept yet. Callers read null as
     * "no filtering", which keeps unscoped requests on their existing SQL.
     */
    public static function forAccess(System $system, ?AccessContext $access): ?self
    {
        if ($access === null || $access->readAll) {
            return null;
        }

        $cifId = $system->systemConcept->get('contained_in_file', null, false);
        if (!is_numeric($cifId) || (int) $cifId <= 0) {
            return null;
        }

        return new self(
            $system,
            (int) $cifId,
            (int) $system->deletedUNID,
            $system->linkTable,
            array_values(array_map('intval', array_keys($access->allowedRead))),
        );
    }

    /**
     * SQL to AND into any query over the link table.
     *
     * Every id is cast to int before interpolation, so the fragment carries no
     * bindable parameter and can be dropped into queries that already use named
     * or positional binds without colliding with them.
     *
     * @param string   $alias   alias of the link table in the outer query
     * @param string[] $columns endpoint columns to constrain
     */
    public function sqlFilter(string $alias, array $columns = ['idConceptStart', 'idConceptTarget']): string
    {
        $sql = '';

        foreach (array_values($columns) as $i => $column) {
            $probe = 'aclv' . $i;
            $sql .= " AND NOT EXISTS ("
                . "SELECT 1 FROM `{$this->linkTable}` {$probe}"
                . " WHERE {$probe}.idConceptStart = {$alias}.`{$column}`"
                . " AND {$probe}.idConceptLink = {$this->cifId}"
                . " AND {$probe}.flag != {$this->deletedId}"
                . $this->deniedFileClause($probe)
                . ")";
        }

        return $sql;
    }

    /**
     * Subset of these concepts the principal may see — the PHP-side twin of
     * sqlFilter(), for the paths where the candidates are already in hand
     * (semantic-search hits, concept listings) rather than in a joinable query.
     * A concept with no contained_in_file is vocabulary and survives.
     *
     * @param  int[] $conceptIds
     * @return int[] in the order given
     */
    public function visibleConcepts(array $conceptIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conceptIds))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT idConceptStart, idConceptTarget FROM `{$this->linkTable}`
                WHERE idConceptLink = ? AND idConceptStart IN ($placeholders) AND flag != ?";

        $bound = [];
        foreach (array_merge([$this->cifId], $ids, [$this->deletedId]) as $i => $value) {
            $bound[$i + 1] = $value;
        }

        $stmt = QueryExecutor::execute($this->system->getConnection(), $sql, $bound);
        $hidden = [];
        if ($stmt !== null) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!in_array((int) $row['idConceptTarget'], $this->allowedFiles, true)) {
                    $hidden[(int) $row['idConceptStart']] = true;
                }
            }
        }

        return array_values(array_filter($conceptIds, static fn ($id): bool => !isset($hidden[(int) $id])));
    }

    /**
     * The endpoint is hidden when it sits in a file that was not granted. With
     * no grant at all, ANY containment hides it — only bare concepts survive.
     */
    private function deniedFileClause(string $probe): string
    {
        if ($this->allowedFiles === []) {
            return '';
        }

        return " AND {$probe}.idConceptTarget NOT IN (" . implode(', ', $this->allowedFiles) . ")";
    }
}
