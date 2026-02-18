<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use PDO;
use SandraCore\EntityFactory;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * Discovers entity factories by scanning the Sandra database for
 * distinct (is_a, contained_in_file) pairs in the triplets table.
 */
class FactoryDiscovery
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    /**
     * Scan the database and return an array of discovered factories.
     *
     * @return array<string, EntityFactory> name => factory
     */
    public function discover(): array
    {
        $sc = $this->system->systemConcept;
        $isaId = $sc->get('is_a', null, false);
        $cifId = $sc->get('contained_in_file', null, false);

        $this->log("Discovery: linkTable={$this->system->linkTable}, is_a=$isaId, contained_in_file=$cifId");

        // If these system concepts don't exist, there's nothing to discover
        if ($isaId === null || $cifId === null) {
            $this->log("Discovery: is_a or contained_in_file concept not found, aborting");
            return [];
        }

        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $deletedId = $this->system->deletedUNID;

        // Find all distinct (is_a target, contained_in_file target) pairs
        // by joining the triplets table on itself: same subject concept,
        // one row for is_a, one for contained_in_file.
        $sql = "SELECT DISTINCT
                    isa.idConceptTarget AS isaTarget,
                    cif.idConceptTarget AS cifTarget
                FROM `{$linkTable}` isa
                INNER JOIN `{$linkTable}` cif
                    ON isa.idConceptStart = cif.idConceptStart
                WHERE isa.idConceptLink = :isaId
                  AND cif.idConceptLink = :cifId
                  AND isa.flag != :deleted1
                  AND cif.flag != :deleted2";

        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':isaId' => [(int)$isaId, PDO::PARAM_INT],
            ':cifId' => [(int)$cifId, PDO::PARAM_INT],
            ':deleted1' => [(int)$deletedId, PDO::PARAM_INT],
            ':deleted2' => [(int)$deletedId, PDO::PARAM_INT],
        ]);

        $this->log("Discovery: query returned " . ($rows === null ? 'null' : count($rows)) . " rows");

        if (empty($rows)) {
            return [];
        }

        $factories = [];
        foreach ($rows as $row) {
            $isaShortname = $sc->getShortname($row['isaTarget']);
            $cifShortname = $sc->getShortname($row['cifTarget']);

            if ($isaShortname === null || $cifShortname === null) {
                $this->log("Discovery: skipping row isaTarget={$row['isaTarget']} cifTarget={$row['cifTarget']} (no shortname)");
                continue;
            }

            // Use isaShortname as the factory name (deduplicate if needed)
            $name = $isaShortname;
            if (isset($factories[$name])) {
                $name = $isaShortname . '_' . $cifShortname;
            }

            $this->log("Discovery: found factory '$name' (is_a=$isaShortname, file=$cifShortname)");
            $factories[$name] = new EntityFactory($isaShortname, $cifShortname, $this->system);
        }

        $this->log("Discovery: total " . count($factories) . " factories discovered");
        return $factories;
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[sandra-mcp] $message\n");
    }
}
