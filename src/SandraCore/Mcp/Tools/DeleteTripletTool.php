<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

class DeleteTripletTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_delete_triplet';
    }

    public function description(): string
    {
        return 'Soft-delete a triplet by its link ID (sets the deleted flag). The triplet is not physically removed but will be excluded from all queries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'linkId' => [
                    'type' => 'integer',
                    'description' => 'The triplet link ID to delete (from sandra_get_triplets or sandra_create_triplet)',
                ],
            ],
            'required' => ['linkId'],
        ];
    }

    public function execute(array $args): mixed
    {
        $linkId = (int)($args['linkId'] ?? 0);
        if ($linkId <= 0) {
            throw new \InvalidArgumentException('linkId must be a positive integer');
        }

        $pdo = $this->system->getConnection();
        $tableLink = $this->system->linkTable;
        $deletedUNID = (int)$this->system->deletedUNID;

        // Verify the triplet exists and is not already deleted
        $rows = QueryExecutor::fetchAll($pdo,
            "SELECT id, idConceptStart, idConceptLink, idConceptTarget, flag FROM $tableLink WHERE id = :linkId",
            [':linkId' => [$linkId, \PDO::PARAM_INT]]
        );

        if (empty($rows)) {
            throw new \InvalidArgumentException("Triplet with linkId $linkId not found");
        }

        $triplet = $rows[0];
        if ((int)$triplet['flag'] === $deletedUNID) {
            return [
                'linkId' => $linkId,
                'deleted' => false,
                'message' => 'Triplet was already deleted',
            ];
        }

        // Soft-delete by setting flag to deletedUNID
        QueryExecutor::execute($pdo,
            "UPDATE $tableLink SET flag = :deletedFlag WHERE id = :linkId",
            [
                ':deletedFlag' => [$deletedUNID, \PDO::PARAM_INT],
                ':linkId' => [$linkId, \PDO::PARAM_INT],
            ]
        );

        return [
            'linkId' => $linkId,
            'deleted' => true,
            'subjectId' => (int)$triplet['idConceptStart'],
            'verbId' => (int)$triplet['idConceptLink'],
            'targetId' => (int)$triplet['idConceptTarget'],
        ];
    }
}
