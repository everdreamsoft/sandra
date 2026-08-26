<?php
declare(strict_types=1);

namespace SandraCore\Acl;

use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Endpoint- and facet-derived visibility of triplets.
 *
 * Sandra's ACL unit is the `contained_in_file` concept: a grant makes the
 * ENTITIES of a file readable. A triplet is not itself contained in a file, so
 * file grants alone leave the LINKS visible — and the link is often the secret.
 * "Address X is memberOf group #4711" discloses the clustering even when the
 * group entity cannot be read.
 *
 * FACETS. A concept may carry SEVERAL `contained_in_file` triplets, and refs
 * hang on that triplet (`References.linkReferenced`), so each file holds a
 * DISJOINT set of refs for the same concept. That is the structure of the
 * model, not a trick: one person is `salary` and `avs_number` in an HR file and
 * `slack_id` in a colleagues file; one public blockchain address can carry a
 * private label per user who tracks it. Two rules follow.
 *
 *   ENDPOINT RULE — an endpoint is visible when it has NO file (a bare system
 *   concept, i.e. vocabulary), or when AT LEAST ONE of its files is readable.
 *   A triplet needs both its endpoints visible. Requiring EVERY file to be
 *   readable would make the person — or the public address — vanish for
 *   everyone the moment one private facet is attached to it.
 *
 *   CONTAINMENT RULE — a `contained_in_file` triplet IS the facet: its refs and
 *   its storage are the facet's payload, and its target is the file. It is
 *   visible only when that file is readable. The endpoint rule alone would let
 *   it through — the subject is visible via another facet and a file concept is
 *   bare vocabulary — and a colleague would learn that the person has an HR
 *   record, its storage included.
 *
 * Reading one granted facet never exposes another: a factory only ever queries
 * its own file (`EntityFactory::populateLocal`), so an ungranted facet's refs
 * are not in the result to begin with.
 *
 * COROLLARY, and a footgun worth stating plainly: a concept that is ALREADY in
 * a readable file cannot be locked down by adding a private file to it — one
 * does not un-publish by publishing again. The "private tag" idiom (give a bare
 * concept its own file so every link touching it follows that file's grants)
 * therefore only holds for a concept with NO other file. And never model a
 * concept whose SHORTNAME is itself the secret as a facet: the name leaks
 * through `sandra_list_concepts`, `sandra_search` and `sandra_find_concept` to
 * any reader of any of its files.
 *
 * Writes stay strict and deliberately asymmetric — see WriteGuard. Reading is
 * per facet; writing is judged on the facet being written.
 *
 * The filter is a SQL fragment, never a post-pass in PHP: LIMIT, COUNT and
 * NOT HAS must be evaluated on the filtered graph, otherwise the negative
 * answer leaks what the positive one hides. For the principal the triplet does
 * not exist — this is a view, not a mask.
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
     * Endpoint rule only. Safe on any table exposing endpoint id columns —
     * notably the CONCEPT table, where `sqlFilter('c', ['id'])` filters a
     * listing and there is no link for the containment rule to apply to.
     *
     * Every id is cast to int before interpolation, so the fragment carries no
     * bindable parameter and drops into queries that already use named or
     * positional binds without colliding with them.
     *
     * @param string   $alias   alias of the table in the outer query
     * @param string[] $columns endpoint columns to constrain
     */
    public function sqlFilter(string $alias, array $columns = ['idConceptStart', 'idConceptTarget']): string
    {
        $sql = '';

        foreach (array_values($columns) as $i => $column) {
            $sql .= ' AND ' . $this->endpointPredicate("{$alias}.`{$column}`", 'aclv' . $i);
        }

        return $sql;
    }

    /**
     * Endpoint rule AND containment rule. Use this wherever `$alias` is a row
     * of the link table — which is every call site except a concept listing.
     */
    public function linkFilter(string $alias): string
    {
        // Subject: always the endpoint rule.
        $sql = ' AND ' . $this->endpointPredicate("{$alias}.`idConceptStart`", 'aclv0');

        // Target: exempt on a contained_in_file link. There the target is the
        // FILE — a label, not data — and the rule about it is "is this file
        // granted to me", which is exactly the containment clause below. The
        // two rules overlap on this link and nobody notices, until file
        // concepts are themselves filed (e.g. under an architect file so they
        // stop being enumerable): the endpoint rule would then hide the link,
        // and with it the refs hanging on it — an owner would lose sight of
        // their own facet.
        $sql .= " AND ({$alias}.idConceptLink = {$this->cifId}"
            . ' OR ' . $this->endpointPredicate("{$alias}.`idConceptTarget`", 'aclv1') . ')';

        return $sql . $this->containmentClause($alias);
    }

    /**
     * "This endpoint has no file, or one of its files is granted", as a single
     * correlated probe. The aggregate makes the three cases explicit:
     *
     *   no cif row at all      -> MAX over an empty set is NULL -> COALESCE 1 -> visible
     *   cif rows, one allowed  ->                              1             -> visible
     *   cif rows, none allowed ->                              0             -> hidden
     *
     * Written as `NOT EXISTS(...) OR EXISTS(...)` it would cost two correlated
     * probes per endpoint column — four on a two-column filter, on every row of
     * a full concept-table scan. The probe's WHERE is a two-column prefix of the
     * unique key (idConceptStart, idConceptLink, idConceptTarget); the target
     * test simply sits in the SELECT list, where the column is still read from
     * the index.
     */
    private function endpointPredicate(string $endpoint, string $probe): string
    {
        if ($this->allowedFiles === []) {
            // No grant at all: any containment hides, bare concepts survive.
            return "NOT EXISTS ({$this->filedAs($probe, $endpoint)})";
        }

        $allowed = implode(', ', $this->allowedFiles);

        return 'COALESCE(('
            . "SELECT MAX(CASE WHEN {$probe}.idConceptTarget IN ({$allowed}) THEN 1 ELSE 0 END)"
            . ' FROM `' . $this->linkTable . "` {$probe}"
            . " WHERE {$probe}.idConceptStart = {$endpoint}"
            . " AND {$probe}.idConceptLink = {$this->cifId}"
            . " AND {$probe}.flag != {$this->deletedId}"
            . '), 1) = 1';
    }

    /** A cif link IS the facet: it lives or dies with its target file. */
    private function containmentClause(string $alias): string
    {
        if ($this->allowedFiles === []) {
            return " AND {$alias}.idConceptLink != {$this->cifId}";
        }

        return " AND ({$alias}.idConceptLink != {$this->cifId}"
            . " OR {$alias}.idConceptTarget IN (" . implode(', ', $this->allowedFiles) . '))';
    }

    /**
     * Correlated probe for "this endpoint is filed somewhere", served by the
     * unique key (idConceptStart, idConceptLink, idConceptTarget).
     */
    private function filedAs(string $probe, string $endpoint): string
    {
        return 'SELECT 1 FROM `' . $this->linkTable . "` {$probe}"
            . " WHERE {$probe}.idConceptStart = {$endpoint}"
            . " AND {$probe}.idConceptLink = {$this->cifId}"
            . " AND {$probe}.flag != {$this->deletedId}";
    }

    /**
     * Endpoint rule in PHP, for the paths where the candidates are already in
     * hand (semantic-search hits, concept listings) rather than in a joinable
     * query. A concept with no contained_in_file is vocabulary and survives.
     *
     * @param  int[] $conceptIds
     * @return int[] in the order given
     */
    public function visibleConcepts(array $conceptIds): array
    {
        [$filed, $granted] = $this->fileMap($conceptIds);

        return array_values(array_filter(
            $conceptIds,
            static fn ($id): bool => !isset($filed[(int) $id]) || isset($granted[(int) $id])
        ));
    }

    /**
     * The stricter twin: visible only when EVERY file is readable.
     *
     * For the paths where a hit discloses CONTENT rather than existence and the
     * content cannot be narrowed to one facet. Semantic search is exactly that:
     * the embedding table is keyed by concept id — one vector per concept, not
     * one per facet — so whichever facet was embedded last owns the vector, and
     * the similarity score alone would disclose an ungranted facet's text.
     *
     * @param  int[] $conceptIds
     * @return int[] in the order given
     */
    public function fullyVisibleConcepts(array $conceptIds): array
    {
        [, , $denied] = $this->fileMap($conceptIds);

        return array_values(array_filter(
            $conceptIds,
            static fn ($id): bool => !isset($denied[(int) $id])
        ));
    }

    /**
     * One indexed query, three maps: filed at all / filed somewhere granted /
     * filed somewhere ungranted.
     *
     * @param  int[] $conceptIds
     * @return array{0: array<int,true>, 1: array<int,true>, 2: array<int,true>}
     */
    private function fileMap(array $conceptIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conceptIds))));
        if ($ids === [] || $this->cifId <= 0) {
            return [[], [], []];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT idConceptStart, idConceptTarget FROM `{$this->linkTable}`
                WHERE idConceptLink = ? AND idConceptStart IN ($placeholders) AND flag != ?";

        $bound = [];
        foreach (array_merge([$this->cifId], $ids, [$this->deletedId]) as $i => $value) {
            $bound[$i + 1] = $value;
        }

        $stmt = QueryExecutor::execute($this->system->getConnection(), $sql, $bound);
        $filed = $granted = $denied = [];

        if ($stmt !== null) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $concept = (int) $row['idConceptStart'];
                $filed[$concept] = true;
                if (in_array((int) $row['idConceptTarget'], $this->allowedFiles, true)) {
                    $granted[$concept] = true;
                } else {
                    $denied[$concept] = true;
                }
            }
        }

        return [$filed, $granted, $denied];
    }
}
