<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Returns raw triplets (links) for a given concept ID directly from the database.
 * Resolves concept IDs to shortnames for readability.
 * Does NOT load any factory into memory.
 */
class GetTripletsTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
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
            ],
            'required' => ['conceptId'],
        ];
    }

    public function execute(array $args): mixed
    {
        $conceptId = (int)($args['conceptId'] ?? 0);
        $direction = $args['direction'] ?? 'both';
        $countOnly = (bool)($args['count_only'] ?? false);

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $conceptTable = $this->system->conceptTable;
        $deletedId = (int)$this->system->deletedUNID;

        if ($countOnly) {
            return $this->executeCountOnly($pdo, $linkTable, $conceptId, $deletedId, $direction);
        }

        $results = [];

        // Outgoing: concept is the subject
        if ($direction === 'outgoing' || $direction === 'both') {
            $sql = "SELECT l.id AS linkId,
                           l.idConceptStart, l.idConceptLink, l.idConceptTarget, l.flag,
                           cs.shortname AS startName,
                           cl.shortname AS linkName,
                           ct.shortname AS targetName
                    FROM `{$linkTable}` l
                    LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                    LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                    LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                    WHERE l.idConceptStart = :conceptId
                      AND l.flag != :deleted
                    LIMIT 100";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);

            if ($rows) {
                foreach ($rows as $row) {
                    $results[] = [
                        'direction' => 'outgoing',
                        'linkId' => (int)$row['linkId'],
                        'subject' => $row['startName'] ?? (string)$row['idConceptStart'],
                        'verb' => $row['linkName'] ?? (string)$row['idConceptLink'],
                        'target' => $row['targetName'] ?? (string)$row['idConceptTarget'],
                        'subjectId' => (int)$row['idConceptStart'],
                        'verbId' => (int)$row['idConceptLink'],
                        'targetId' => (int)$row['idConceptTarget'],
                    ];
                }
            }
        }

        // Incoming: concept is the target
        if ($direction === 'incoming' || $direction === 'both') {
            $sql = "SELECT l.id AS linkId,
                           l.idConceptStart, l.idConceptLink, l.idConceptTarget, l.flag,
                           cs.shortname AS startName,
                           cl.shortname AS linkName,
                           ct.shortname AS targetName
                    FROM `{$linkTable}` l
                    LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                    LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                    LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                    WHERE l.idConceptTarget = :conceptId
                      AND l.flag != :deleted
                    LIMIT 100";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);

            if ($rows) {
                foreach ($rows as $row) {
                    $results[] = [
                        'direction' => 'incoming',
                        'linkId' => (int)$row['linkId'],
                        'subject' => $row['startName'] ?? (string)$row['idConceptStart'],
                        'verb' => $row['linkName'] ?? (string)$row['idConceptLink'],
                        'target' => $row['targetName'] ?? (string)$row['idConceptTarget'],
                        'subjectId' => (int)$row['idConceptStart'],
                        'verbId' => (int)$row['idConceptLink'],
                        'targetId' => (int)$row['idConceptTarget'],
                    ];
                }
            }
        }

        return [
            'conceptId' => $conceptId,
            'triplets' => $results,
            'total' => count($results),
        ];
    }

    private function executeCountOnly(\PDO $pdo, string $linkTable, int $conceptId, int $deletedId, string $direction): array
    {
        $outgoing = 0;
        $incoming = 0;

        if ($direction === 'outgoing' || $direction === 'both') {
            $sql = "SELECT COUNT(*) FROM `{$linkTable}` WHERE idConceptStart = :conceptId AND flag != :deleted";
            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':deleted' => [$deletedId, PDO::PARAM_INT],
            ]);
            $outgoing = (int)($rows[0]['COUNT(*)'] ?? 0);
        }

        if ($direction === 'incoming' || $direction === 'both') {
            $sql = "SELECT COUNT(*) FROM `{$linkTable}` WHERE idConceptTarget = :conceptId AND flag != :deleted";
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
