<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Returns all references (key-value data) attached to a concept's links.
 * Resolves reference key concept IDs to shortnames.
 * Does NOT load any factory into memory.
 */
class GetReferencesTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_get_references';
    }

    public function description(): string
    {
        return 'Get all reference key-value pairs attached to a concept\'s links. Use this to read the actual data/properties stored on entities or concepts that are not in a factory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conceptId' => [
                    'type' => 'integer',
                    'description' => 'The concept ID to get references for',
                ],
                'linkId' => [
                    'type' => 'integer',
                    'description' => 'Optional: a specific link ID to get references for (instead of all links for a concept)',
                ],
            ],
            'required' => ['conceptId'],
        ];
    }

    public function execute(array $args): mixed
    {
        $conceptId = (int)($args['conceptId'] ?? 0);
        $linkId = isset($args['linkId']) ? (int)$args['linkId'] : null;

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $refTable = $this->system->tableReference;
        $conceptTable = $this->system->conceptTable;
        $deletedId = (int)$this->system->deletedUNID;

        $params = [];

        if ($linkId !== null) {
            // Get references for a specific link
            $where = 'l.id = :linkId AND l.flag != :deleted';
            $params[':linkId'] = [$linkId, PDO::PARAM_INT];
        } else {
            // Get references for all outgoing links of a concept
            $where = 'l.idConceptStart = :conceptId AND l.flag != :deleted';
            $params[':conceptId'] = [$conceptId, PDO::PARAM_INT];
        }
        $params[':deleted'] = [$deletedId, PDO::PARAM_INT];

        $sql = "SELECT r.id AS refId,
                       r.idConcept AS refKeyId,
                       r.value AS refValue,
                       r.linkReferenced AS linkId,
                       l.idConceptStart, l.idConceptLink, l.idConceptTarget,
                       ck.shortname AS refKeyName,
                       cl.shortname AS linkName,
                       ct.shortname AS targetName
                FROM `{$refTable}` r
                INNER JOIN `{$linkTable}` l ON l.id = r.linkReferenced
                LEFT JOIN `{$conceptTable}` ck ON r.idConcept = ck.id
                LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                WHERE {$where}
                ORDER BY l.id, r.idConcept
                LIMIT 500";

        $rows = QueryExecutor::fetchAll($pdo, $sql, $params);

        // Group references by link
        $linkRefs = [];
        if ($rows) {
            foreach ($rows as $row) {
                $lid = (int)$row['linkId'];
                if (!isset($linkRefs[$lid])) {
                    $linkRefs[$lid] = [
                        'linkId' => $lid,
                        'verb' => $row['linkName'] ?? (string)$row['idConceptLink'],
                        'target' => $row['targetName'] ?? (string)$row['idConceptTarget'],
                        'verbId' => (int)$row['idConceptLink'],
                        'targetId' => (int)$row['idConceptTarget'],
                        'references' => [],
                    ];
                }
                $key = $row['refKeyName'] ?? (string)$row['refKeyId'];
                $linkRefs[$lid]['references'][$key] = $row['refValue'];
            }
        }

        return [
            'conceptId' => $conceptId,
            'links' => array_values($linkRefs),
            'totalLinks' => count($linkRefs),
        ];
    }
}
