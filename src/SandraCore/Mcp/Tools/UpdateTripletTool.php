<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\DatabaseAdapter;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Update mutable state on an existing triplet — currently the long-text
 * storage payload. Mirrors sandra_update_entity for triplet-anchored data.
 */
class UpdateTripletTool implements McpToolInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_update_triplet';
    }

    public function description(): string
    {
        return 'Update the long-text storage payload attached to an existing triplet by its link ID. '
            . 'Use this to set, replace, or clear the storage on a triplet without recreating it. '
            . 'Pass an empty string to clear. Refuses if the triplet is soft-deleted.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'linkId' => [
                    'type' => 'integer',
                    'description' => 'The triplet link ID to update (from sandra_get_triplets or sandra_create_triplet).',
                ],
                'storage' => [
                    'type' => 'string',
                    'description' => 'New long-text storage value. Empty string clears the existing payload.',
                ],
            ],
            'required' => ['linkId', 'storage'],
        ];
    }

    public function execute(array $args): mixed
    {
        $linkId = (int)($args['linkId'] ?? 0);
        if ($linkId <= 0) {
            throw new \InvalidArgumentException('linkId must be a positive integer');
        }

        if (!array_key_exists('storage', $args)) {
            throw new \InvalidArgumentException('storage is required (use empty string to clear)');
        }
        $storage = (string)$args['storage'];

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $storageTable = $this->system->tableStorage;
        $deletedUNID = (int)$this->system->deletedUNID;

        // Verify the triplet exists and is not soft-deleted.
        $rows = QueryExecutor::fetchAll(
            $pdo,
            "SELECT id, idConceptStart, idConceptLink, idConceptTarget, flag FROM $linkTable WHERE id = :linkId",
            [':linkId' => [$linkId, PDO::PARAM_INT]]
        );

        if (empty($rows)) {
            throw new \InvalidArgumentException("Triplet with linkId $linkId not found");
        }

        $triplet = $rows[0];
        if ((int)$triplet['flag'] === $deletedUNID) {
            throw new \InvalidArgumentException("Triplet $linkId is soft-deleted; restore it before updating");
        }

        if ($storage === '') {
            // Explicit clear: remove the storage row entirely.
            QueryExecutor::execute(
                $pdo,
                "DELETE FROM $storageTable WHERE linkReferenced = :linkId",
                [':linkId' => [$linkId, PDO::PARAM_INT]]
            );

            return [
                'linkId' => $linkId,
                'subjectId' => (int)$triplet['idConceptStart'],
                'verbId' => (int)$triplet['idConceptLink'],
                'targetId' => (int)$triplet['idConceptTarget'],
                'storageCleared' => true,
            ];
        }

        DatabaseAdapter::rawSetStorage($linkId, $storage, $this->system);

        return [
            'linkId' => $linkId,
            'subjectId' => (int)$triplet['idConceptStart'],
            'verbId' => (int)$triplet['idConceptLink'],
            'targetId' => (int)$triplet['idConceptTarget'],
            'storageSet' => true,
            'bytes' => strlen($storage),
        ];
    }
}
