<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Acl\AccessContext;
use SandraCore\Acl\TripletVisibility;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Returns raw triplets (links) for a given concept ID directly from the database.
 * Resolves concept IDs to shortnames for readability.
 * Does NOT load any factory into memory.
 *
 * ACL-aware: for a principal-scoped request every query — details AND counts —
 * carries the TripletVisibility filter, so a link touching an unreadable file
 * simply does not exist for that caller. Counts go through the same fragment on
 * purpose: a count that still saw the hidden links would give away what the
 * detail listing withholds.
 */
class GetTripletsTool implements McpToolInterface, AclAwareToolInterface
{
    private System $system;
    private ?AccessContext $access = null;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    public function name(): string
    {
        return 'sandra_get_triplets';
    }

    public function description(): string
    {
        return 'Get raw triplets (subject-verb-target links) for a concept ID. '
            . 'Works with both entity concept IDs and system concept IDs. '
            . 'Use sandra_search to find IDs (results are tagged with type "entity" or "system_concept"). '
            . 'Shows all graph relationships without loading factories.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conceptId' => [
                    'type' => 'integer',
                    'description' => 'The concept ID to look up triplets for',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['outgoing', 'incoming', 'both'],
                    'description' => 'Direction: outgoing (concept is subject), incoming (concept is target), or both (default: both)',
                ],
                'count_only' => [
                    'type' => 'boolean',
                    'description' => 'If true, return only counts (no triplet details). Much lighter response for large graphs.',
                ],
                'include_storage' => [
                    'type' => 'boolean',
                    'description' => 'If true, include the long-text storage payload attached to each triplet (via LEFT JOIN). Default false.',
                ],
            ],
            'required' => ['conceptId'],
        ];
    }

    public function execute(array $args): mixed
    {
        $conceptId = (int)($args['conceptId'] ?? 0);
        $direction = $args['direction'] ?? 'both';
        $countOnly = (bool)($args['count_only'] ?? false);
        $includeStorage = (bool)($args['include_storage'] ?? false);

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $conceptTable = $this->system->conceptTable;
        $storageTable = $this->system->tableStorage;
        $deletedId = (int)$this->system->deletedUNID;
        $aclFilter = TripletVisibility::forAccess($this->system, $this->access)?->linkFilter('l') ?? '';

        if ($countOnly) {
            return $this->executeCountOnly($pdo, $linkTable, $conceptId, $deletedId, $direction, $aclFilter);
        }

        $storageSelect = $includeStorage ? ', ds.`value` AS storage' : '';
        $storageJoin = $includeStorage
            ? "LEFT JOIN `{$storageTable}` ds ON ds.linkReferenced = l.id"
            : '';

        $results = [];

        // Outgoing: concept is the subject
        if ($direction === 'outgoing' || $direction === 'both') {
            $sql = "SELECT l.id AS linkId,
                           l.idConceptStart, l.idConceptLink, l.idConceptTarget, l.flag,
                           cs.shortname AS startName,
                           cl.shortname AS linkName,
                           ct.shortname AS targetName{$storageSelect}
                    FROM `{$linkTable}` l
                    LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                    LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                    LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                    {$storageJoin}
                    WHERE l.idConceptStart = :conceptId
                      AND l.flag != :deleted{$aclFilter}
                    LIMIT 100";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);

            if ($rows) {
                foreach ($rows as $row) {
                    $entry = [
                        'direction' => 'outgoing',
                        'linkId' => (int)$row['linkId'],
                        'subject' => $row['startName'] ?? (string)$row['idConceptStart'],
                        'verb' => $row['linkName'] ?? (string)$row['idConceptLink'],
                        'target' => $row['targetName'] ?? (string)$row['idConceptTarget'],
                        'subjectId' => (int)$row['idConceptStart'],
                        'verbId' => (int)$row['idConceptLink'],
                        'targetId' => (int)$row['idConceptTarget'],
                    ];
                    if ($includeStorage) {
                        $entry['storage'] = $row['storage'] ?? null;
                    }
                    $results[] = $entry;
                }
            }
        }

        // Incoming: concept is the target
        if ($direction === 'incoming' || $direction === 'both') {
            $sql = "SELECT l.id AS linkId,
                           l.idConceptStart, l.idConceptLink, l.idConceptTarget, l.flag,
                           cs.shortname AS startName,
                           cl.shortname AS linkName,
                           ct.shortname AS targetName{$storageSelect}
                    FROM `{$linkTable}` l
                    LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                    LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                    LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                    {$storageJoin}
                    WHERE l.idConceptTarget = :conceptId
                      AND l.flag != :deleted{$aclFilter}
                    LIMIT 100";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);

            if ($rows) {
                foreach ($rows as $row) {
                    $entry = [
                        'direction' => 'incoming',
                        'linkId' => (int)$row['linkId'],
                        'subject' => $row['startName'] ?? (string)$row['idConceptStart'],
                        'verb' => $row['linkName'] ?? (string)$row['idConceptLink'],
                        'target' => $row['targetName'] ?? (string)$row['idConceptTarget'],
                        'subjectId' => (int)$row['idConceptStart'],
                        'verbId' => (int)$row['idConceptLink'],
                        'targetId' => (int)$row['idConceptTarget'],
                    ];
                    if ($includeStorage) {
                        $entry['storage'] = $row['storage'] ?? null;
                    }
                    $results[] = $entry;
                }
            }
        }

        return [
            'conceptId' => $conceptId,
            'triplets' => $results,
            'total' => count($results),
        ];
    }

    private function executeCountOnly(\PDO $pdo, string $linkTable, int $conceptId, int $deletedId, string $direction, string $aclFilter = ''): array
    {
        $outgoing = 0;
        $incoming = 0;

        if ($direction === 'outgoing' || $direction === 'both') {
            $sql = "SELECT COUNT(*) FROM `{$linkTable}` l WHERE l.idConceptStart = :conceptId AND l.flag != :deleted{$aclFilter}";
            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);
            $outgoing = (int)($rows[0]['COUNT(*)'] ?? 0);
        }

        if ($direction === 'incoming' || $direction === 'both') {
            $sql = "SELECT COUNT(*) FROM `{$linkTable}` l WHERE l.idConceptTarget = :conceptId AND l.flag != :deleted{$aclFilter}";
            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);
            $incoming = (int)($rows[0]['COUNT(*)'] ?? 0);
        }

        return [
            'conceptId' => $conceptId,
            'counts' => [
                'outgoing' => $outgoing,
                'incoming' => $incoming,
                'total' => $outgoing + $incoming,
            ],
        ];
    }
}
