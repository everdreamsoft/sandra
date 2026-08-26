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
    private ?string $logFile;

    public function __construct(System $system, ?string $logFile = null)
    {
        $this->system = $system;
        $this->logFile = $logFile;
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
                  AND cif.flag != :deleted2
                ORDER BY isa.idConceptTarget ASC, cif.idConceptTarget ASC";

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

            // The oldest pair keeps the bare is_a — deterministic thanks to the
            // ORDER BY above, so a facet added later never steals a name that
            // clients already use. Every further file for that is_a is qualified.
            $name = isset($factories[$isaShortname])
                ? self::qualifiedName($isaShortname, $cifShortname)
                : $isaShortname;

            $this->log("Discovery: found factory '$name' (is_a=$isaShortname, file=$cifShortname)");
            $factories[$name] = new EntityFactory($isaShortname, $cifShortname, $this->system);
        }

        $this->log("Discovery: total " . count($factories) . " factories discovered");
        return $factories;
    }

    /**
     * Registry name of a factory identified by its PAIR, for every file beyond
     * the first one of that is_a.
     *
     * Facets make several files per is_a the norm rather than the exception —
     * `person` lives in `person_file`, in `eds_employee_file` and in
     * `eds_colleagues_file`. Keying by is_a alone meant the first pair returned
     * by the optimiser took the bare name and the rest got `{isa}_{cif}`, with
     * no ORDER BY: which one won flipped between boots.
     *
     *   person, first file seen -> person
     *   person / eds_employee_file (a later facet) -> person@eds_employee_file
     *
     * `@` rather than `_` because `{isa}_{cif}` is ambiguous: `person_eds` +
     * `employee_file` would collide with `person` + `eds_employee_file`.
     *
     * The bare name is NOT reserved for a `{isa}_file` convention: real graphs
     * name files freely (`livresFile`, `blockchainAddressFile`), so reserving it
     * would rename nearly every existing factory.
     */
    public static function qualifiedName(string $isa, string $cif): string
    {
        return $isa . '@' . $cif;
    }

    private function log(string $message): void
    {
        $line = "[sandra-mcp] $message\n";
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        } else {
            fwrite(STDERR, $line);
        }
    }
}
