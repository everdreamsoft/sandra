<?php
declare(strict_types=1);

namespace SandraCore\Search;

use PDO;
use SandraCore\EntityFactory;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * SQL-based search that queries the database directly with LIKE.
 * Avoids loading all entities into memory (populateLocal).
 */
class SqlSearch implements SearchInterface
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function search(EntityFactory $factory, string $query, int $limit = 50): array
    {
        return $this->doSearch($factory, $query, null, $limit);
    }

    public function searchByField(EntityFactory $factory, string $field, string $query, int $limit = 50): array
    {
        return $this->doSearch($factory, $query, $field, $limit);
    }

    private function doSearch(EntityFactory $factory, string $query, ?string $field, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // If query already contains % wildcards, use it as-is; otherwise wrap with %
        $hasWildcards = str_contains($query, '%');

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $refTable = $this->system->tableReference;
        $conceptTable = $this->system->conceptTable;
        $sc = $this->system->systemConcept;

        $isaId = (int)$sc->get('is_a');
        $cifId = (int)$sc->get('contained_in_file');
        $entityIsaId = (int)$sc->get($factory->entityIsa);
        $entityCifId = (int)$sc->get($factory->entityContainedIn);
        $deletedId = (int)$this->system->deletedUNID;

        // Find all entity concept IDs that match is_a and contained_in_file
        // then join to References to find those whose ref value matches the query
        $params = [
            ':isaId' => [$isaId, PDO::PARAM_INT],
            ':cifId' => [$cifId, PDO::PARAM_INT],
            ':entityIsaId' => [$entityIsaId, PDO::PARAM_INT],
            ':entityCifId' => [$entityCifId, PDO::PARAM_INT],
            ':deleted1' => [$deletedId, PDO::PARAM_INT],
            ':deleted2' => [$deletedId, PDO::PARAM_INT],
            ':query' => [$hasWildcards ? mb_strtolower($query) : '%' . mb_strtolower($query) . '%', PDO::PARAM_STR],
            ':limit' => [$limit, PDO::PARAM_INT],
        ];

        $fieldWhere = '';
        if ($field !== null) {
            $fieldConceptId = $sc->get($field, null, false);
            if ($fieldConceptId === null) {
                return [];
            }
            $fieldWhere = 'AND r.idConcept = :fieldConceptId';
            $params[':fieldConceptId'] = [(int)$fieldConceptId, PDO::PARAM_INT];
        }

        // Step 1: find matching entity concept IDs via References LIKE
        $sql = "SELECT DISTINCT isa_link.idConceptStart AS conceptId
                FROM `{$linkTable}` isa_link
                INNER JOIN `{$linkTable}` cif_link
                    ON isa_link.idConceptStart = cif_link.idConceptStart
                INNER JOIN `{$linkTable}` ref_link
                    ON isa_link.idConceptStart = ref_link.idConceptStart
                INNER JOIN `{$refTable}` r
                    ON ref_link.id = r.linkReferenced
                WHERE isa_link.idConceptLink = :isaId
                  AND isa_link.idConceptTarget = :entityIsaId
                  AND isa_link.flag != :deleted1
                  AND cif_link.idConceptLink = :cifId
                  AND cif_link.idConceptTarget = :entityCifId
                  AND cif_link.flag != :deleted2
                  AND LOWER(r.value) LIKE :query
                  {$fieldWhere}
                LIMIT :limit";

        $rows = QueryExecutor::fetchAll($pdo, $sql, $params);

        if (empty($rows)) {
            return [];
        }

        $conceptIds = array_column($rows, 'conceptId');

        // Step 2: create a fresh factory with only the matched concepts
        $resultFactory = new EntityFactory($factory->entityIsa, $factory->entityContainedIn, $this->system);
        $resultFactory->conceptArray = $conceptIds;
        $resultFactory->populateLocal();

        return $resultFactory->getEntities();
    }
}
