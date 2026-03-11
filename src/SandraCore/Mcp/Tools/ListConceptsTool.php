<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Lists and searches system concepts (the abstract vocabulary of the graph).
 *
 * System concepts are NOT entities. They are universal definitions like
 * "healthy", "is_a", "friend" — shared across the entire system.
 * Entities (clients, products, etc.) use these concepts as verbs and targets
 * in their triplets.
 */
class ListConceptsTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_list_concepts';
    }

    public function description(): string
    {
        return 'List or search system concepts (the abstract vocabulary of the graph). '
            . 'System concepts are universal definitions like "healthy", "is_a", "friend" — NOT entities. '
            . 'Use this to find concept IDs before querying triplets. '
            . 'Supports partial search with % wildcards (e.g. "%health%"). '
            . 'Without a query, lists all concepts with pagination.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional: search pattern. Use % for wildcards (e.g. "%health%"). Case-insensitive. Omit to list all.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 50)',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset (default 0)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $query = trim($args['query'] ?? '');
        $limit = (int)($args['limit'] ?? 50);
        $offset = (int)($args['offset'] ?? 0);

        $pdo = $this->system->getConnection();
        $conceptTable = $this->system->conceptTable;

        $params = [
            ':limit' => [$limit, PDO::PARAM_INT],
            ':offset' => [$offset, PDO::PARAM_INT],
        ];

        if ($query === '') {
            // List all concepts
            $where = "shortname != ''";
        } elseif (str_contains($query, '%')) {
            // LIKE search (case-insensitive)
            $where = "LOWER(shortname) LIKE :query AND shortname != ''";
            $params[':query'] = [mb_strtolower($query), PDO::PARAM_STR];
        } else {
            // Partial match: wrap with %
            $where = "LOWER(shortname) LIKE :query AND shortname != ''";
            $params[':query'] = ['%' . mb_strtolower($query) . '%', PDO::PARAM_STR];
        }

        // Count total
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$conceptTable}` WHERE {$where}";
        $countParams = $params;
        unset($countParams[':limit'], $countParams[':offset']);
        $countRows = QueryExecutor::fetchAll($pdo, $countSql, $countParams);
        $total = (int)($countRows[0]['cnt'] ?? 0);

        // Fetch page
        $sql = "SELECT id, shortname FROM `{$conceptTable}` WHERE {$where} ORDER BY shortname ASC LIMIT :limit OFFSET :offset";
        $rows = QueryExecutor::fetchAll($pdo, $sql, $params);

        $concepts = [];
        if ($rows) {
            foreach ($rows as $row) {
                $concepts[] = [
                    'id' => (int)$row['id'],
                    'shortname' => $row['shortname'],
                ];
            }
        }

        return [
            'concepts' => $concepts,
            'count' => count($concepts),
            'total' => $total,
            'offset' => $offset,
            'hasMore' => ($offset + count($concepts)) < $total,
        ];
    }
}
