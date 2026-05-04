<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;
use SandraCore\Driver\DatabaseDriverInterface;


/**
 * Low-level database operations for the Sandra graph model.
 *
 * Provides static methods for creating concepts, triplets, references,
 * and managing data storage. Uses QueryExecutor for execution and logging.
 */
class DatabaseAdapter
{
    public static ?DatabaseDriverInterface $driver = null;

    /**
     * Create or update a reference value for a triplet.
     *
     * @param mixed $tripletId The triplet (link) ID to attach the reference to
     * @param mixed $conceptId The concept ID defining the reference type
     * @param mixed $value The reference value (truncated to 255 chars)
     * @param System $system The Sandra system instance
     * @param bool $autocommit If false, wraps in a transaction
     * @return string|null The inserted/updated reference ID, or null on error
     */
    public static function rawCreateReference($tripletId, $conceptId, $value, System $system, $autocommit = true)
    {

        if (!isset($value)) {
            return;
        }

        $pdo = $system->getConnection();

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        $targetTable = $system->tableReference;

        $conceptId = (int)$conceptId;
        $tripletId = (int)$tripletId;

        //do we reach the max column data
        $value = (string)$value;
        if (strlen($value) > 255)
            $value = substr($value, 0, 255);

        if (self::$driver !== null) {
            $sql = self::$driver->getUpsertReferenceSQL($targetTable);
            $params = [
                ':conceptId' => [$conceptId, PDO::PARAM_INT],
                ':tripletId' => [$tripletId, PDO::PARAM_INT],
                ':value' => $value,
            ];
            if (self::$driver->getName() === 'mysql') {
                $params[':value2'] = $value;
            }
            $id = QueryExecutor::insert($pdo, $sql, $params);
            // SQLite upsert doesn't update last_insert_rowid on conflict
            if (self::$driver->getName() === 'sqlite' && ($id === '0' || $id === false)) {
                $rows = QueryExecutor::fetchAll($pdo, "SELECT id FROM `$targetTable` WHERE idConcept = :c AND linkReferenced = :t", [
                    ':c' => [$conceptId, PDO::PARAM_INT],
                    ':t' => [$tripletId, PDO::PARAM_INT],
                ]);
                $id = isset($rows[0]['id']) ? (string)$rows[0]['id'] : null;
            }
            if ($opened) {
                TransactionManager::commit();
            }
            return $id;
        }

        $sql = "INSERT INTO `$targetTable`
                (idConcept, linkReferenced, value) VALUES (:conceptId, :tripletId, :value)
                ON DUPLICATE KEY UPDATE  value = :value2, id=LAST_INSERT_ID(id)";

        $id = QueryExecutor::insert($pdo, $sql, [
            ':conceptId' => [$conceptId, PDO::PARAM_INT],
            ':tripletId' => [$tripletId, PDO::PARAM_INT],
            ':value' => $value,
            ':value2' => $value,
        ]);

        if ($opened) {
            TransactionManager::commit();
        }
        return $id;
    }

    /**
     * Create a triplet (Subject-Verb-Object link) in the graph.
     *
     * @param mixed $conceptSubject Subject concept ID
     * @param mixed $conceptVerb Verb (link type) concept ID
     * @param mixed $conceptTarget Target concept ID
     * @param System $system The Sandra system instance
     * @param int $updateOnExistingLK If 1, update existing link instead of creating a new one
     * @param bool $autocommit If false, wraps in a transaction
     * @return string|int|null The triplet ID, or null on error
     */
    public static function rawCreateTriplet($conceptSubject, $conceptVerb, $conceptTarget, System $system, $updateOnExistingLK = 0, $autocommit = true)
    {

        $pdo = $system->getConnection();

        $tableLink = $system->linkTable;

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        $conceptSubject = (int)$conceptSubject;
        $conceptVerb = (int)$conceptVerb;
        $conceptTarget = (int)$conceptTarget;

        //if the link is existing and we try to update it instead of adding a new. For example card - set rarity - rare and we want to change the rarity
        //and not add a new link
        if ($updateOnExistingLK == 1) {

            $sql = "SELECT id FROM $tableLink
                    WHERE idConceptStart = :subject AND idConceptLink = :verb AND flag != :deletedFlag";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':subject' => [$conceptSubject, PDO::PARAM_INT],
                ':verb' => [$conceptVerb, PDO::PARAM_INT],
                ':deletedFlag' => [(int)$system->deletedUNID, PDO::PARAM_INT],
            ]);

            if ($rows) {
                $lastRow = end($rows);
                $updateID = (int)$lastRow['id'];

                $sql = "UPDATE $tableLink SET idConceptTarget = :target WHERE id = :updateId";

                $stmt = QueryExecutor::execute($pdo, $sql, [
                    ':target' => [$conceptTarget, PDO::PARAM_INT],
                    ':updateId' => [$updateID, PDO::PARAM_INT],
                ]);

                if ($stmt === null) {
                    if ($opened) {
                        TransactionManager::commit();
                    }
                    return;
                }

                if ($opened) {
                    TransactionManager::commit();
                }
                return $updateID;
            }
        }


        if (self::$driver !== null) {
            $sql = self::$driver->getUpsertTripletSQL($tableLink);
        } else {
            $sql = "INSERT INTO $tableLink (idConceptStart, idConceptLink, idConceptTarget, flag) VALUES (:subject, :verb, :target, 0) ON DUPLICATE KEY UPDATE flag = 0, id=LAST_INSERT_ID(id)";
        }

        $id = QueryExecutor::insert($pdo, $sql, [
            ':subject' => [$conceptSubject, PDO::PARAM_INT],
            ':verb' => [$conceptVerb, PDO::PARAM_INT],
            ':target' => [$conceptTarget, PDO::PARAM_INT],
        ]);

        // SQLite upsert doesn't update last_insert_rowid on conflict
        if (self::$driver !== null && self::$driver->getName() === 'sqlite' && ($id === '0' || $id === false)) {
            $rows = QueryExecutor::fetchAll($pdo, "SELECT id FROM $tableLink WHERE idConceptStart = :s AND idConceptLink = :l AND idConceptTarget = :t", [
                ':s' => [$conceptSubject, PDO::PARAM_INT],
                ':l' => [$conceptVerb, PDO::PARAM_INT],
                ':t' => [$conceptTarget, PDO::PARAM_INT],
            ]);
            $id = isset($rows[0]['id']) ? (string)$rows[0]['id'] : null;
        }

        if ($opened) {
            TransactionManager::commit();
        }
        return $id;
    }

    /**
     * Store a large data value for an entity (DataStorage table).
     *
     * @param Entity $entity The entity to store data for
     * @param mixed $value The value to store
     * @param bool $autocommit If false, wraps in a transaction
     * @return mixed|null The stored value, or null on error
     */
    public static function setStorage(Entity $entity, $value, $autocommit = true)
    {

        $pdo = $entity->system->getConnection();
        $tableStorage = $entity->system->tableStorage;

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        if (self::$driver !== null) {
            $sql = self::$driver->getUpsertStorageSQL($tableStorage);
            $params = [
                ':storeValue' => $value,
                ':linkId' => [(int)$entity->entityId, PDO::PARAM_INT],
            ];
            if (self::$driver->getName() === 'mysql') {
                $params[':storeValue2'] = $value;
            }
        } else {
            $sql = "INSERT INTO $tableStorage (linkReferenced ,`value` ) VALUES (:linkId,  :storeValue) ON DUPLICATE KEY UPDATE value = :storeValue2";
            $params = [
                ':storeValue' => $value,
                ':storeValue2' => $value,
                ':linkId' => [(int)$entity->entityId, PDO::PARAM_INT],
            ];
        }

        $stmt = QueryExecutor::execute($pdo, $sql, $params);

        if ($opened) {
            TransactionManager::commit();
        }

        return $stmt !== null ? $value : null;
    }

    /**
     * Retrieve the stored data value for an entity.
     *
     * @param Entity $entity The entity to retrieve data for
     * @return string|null The stored value, or null if not found
     */
    public static function getStorage(Entity $entity)
    {

        $pdo = $entity->system->getConnection();
        $tableStorage = $entity->system->tableStorage;

        $sql = "SELECT `value` from $tableStorage WHERE linkReferenced = :entityId LIMIT 1";

        $results = QueryExecutor::fetchAll($pdo, $sql, [
            ':entityId' => [(int)$entity->entityId, PDO::PARAM_INT],
        ]);

        if ($results === null) {
            return;
        }

        $value = null;
        foreach ($results as $result) {
            $value = $result['value'];
        }

        return $value;
    }

    /**
     * Set a flag on an entity's triplet (e.g., mark as deleted).
     *
     * @param Entity $entity The entity to flag
     * @param Concept $flag The flag concept (e.g., deleted)
     * @param System $system The Sandra system instance
     * @param bool $autocommit If false, wraps in a transaction
     */
    public static function rawFlag(Entity $entity, Concept $flag, System $system, $autocommit = true)
    {

        $pdo = $system->getConnection();
        $tableLink = $system->linkTable;

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        $sql = "UPDATE $tableLink SET flag = :flagId WHERE id = :entityId";

        QueryExecutor::execute($pdo, $sql, [
            ':flagId' => [(int)$flag->idConcept, PDO::PARAM_INT],
            ':entityId' => [(int)$entity->entityId, PDO::PARAM_INT],
        ]);

        if ($opened) {
            TransactionManager::commit();
        }
    }

    /**
     * Create a new concept in the concept table.
     *
     * @param mixed $code The concept code/identifier
     * @param System $system The Sandra system instance
     * @param bool $autocommit If false, wraps in a transaction
     * @return string|null The new concept ID, or null on error
     */
    public static function rawCreateConcept($code, System $system, $autocommit = true)
    {

        $pdo = $system->getConnection();
        $tableConcept = $system->conceptTable;

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        $sql = "INSERT INTO $tableConcept (code) VALUES (:code)";

        $id = QueryExecutor::insert($pdo, $sql, [
            ':code' => $code,
        ]);

        if ($opened) {
            TransactionManager::commit();
        }

        return $id;
    }

    /**
     * Get all triplets originating from a concept, optionally filtered by verb and target.
     *
     * @param System $system The Sandra system instance
     * @param mixed $conceptStart The subject concept ID
     * @param int $conceptLink Filter by verb concept ID (0 = any)
     * @param int $conceptTarget Filter by target concept ID (0 = any)
     * @param int $getIds If true, include link IDs in the result
     * @param int $su Super user mode (forced to 1)
     * @return array|null Triplets grouped by verb concept ID
     */
    public static function rawGetTriplets(System $system, $conceptStart, $conceptLink = 0, $conceptTarget = 0, $getIds = 0, $su = 0)
    {
        // Force into Super User
        $su = 1;

        $pdo = $system->getConnection();
        $tableLink = $system->linkTable;

        $conceptStart = (int)$conceptStart;
        $conceptLink = (int)$conceptLink;
        $conceptTarget = (int)$conceptTarget;

        $linkSQL = "";
        $targetSQL = "";
        $params = [':conceptStart' => [$conceptStart, PDO::PARAM_INT]];

        if ($conceptLink != 0) {
            $linkSQL = " AND idConceptLink = :conceptLink";
            $params[':conceptLink'] = [$conceptLink, PDO::PARAM_INT];
        }

        if ($conceptTarget != 0) {
            $targetSQL = " AND idConceptTarget = :conceptTarget";
            $params[':conceptTarget'] = [$conceptTarget, PDO::PARAM_INT];
        }

        $sql = "SELECT * FROM `$tableLink` WHERE idConceptStart = :conceptStart" . $linkSQL . $targetSQL .
            " AND flag = 0";

        $results = QueryExecutor::fetchAll($pdo, $sql, $params);

        if ($results === null) {
            return;
        }

        $array = array();
        foreach ($results as $result) {
            $idLink = $result['id'];
            $idConceptTarget = $result['idConceptTarget'];
            $link = $result['idConceptLink'];
            if ($getIds) {

                $array[$link][]['value'] = $idConceptTarget;

                // Get the last inserted key
                $keys = array_keys($array[$link]);
                $key = end($keys);

                $array[$link][$key]['linkId'] = $idLink;
            } else {
                $array[$link][] = $idConceptTarget;
            }

        }

        return $array;

    }

    /**
     * Search for concepts by reference value, with optional filters.
     *
     * @param System $system The Sandra system instance
     * @param mixed $valueToSearch Value or array of values to search for (true = any non-null)
     * @param mixed $referenceUNID Reference concept to search in ('IS NOT NULL' for any)
     * @param mixed $conceptLinkConcept Filter by verb concept (empty = any)
     * @param mixed $conceptTargetConcept Filter by target concept (empty = any)
     * @param mixed $limit Max results (empty = unlimited)
     * @param mixed $random If truthy, randomize order
     * @param mixed $advanced If true, return detailed result arrays
     * @return array|null Array of concept IDs (or detailed arrays if $advanced)
     */
    public static function searchConcept(System $system, $valueToSearch, $referenceUNID = 'IS NOT NULL', $conceptLinkConcept = '',
                                                $conceptTargetConcept = '', $limit = '', $random = '', $advanced = null)
    {
        $targetConceptSQL = '';
        $linkConceptSQL = '';
        $randomSQL = '';
        $limitSQL = '';
        $tableReference = $system->tableReference;


        if (!$referenceUNID) $referenceUNID = 'IS NOT NULL';

        if ($referenceUNID != 'IS NOT NULL' && !is_numeric($referenceUNID)) $referenceUNID = CommonFunctions::somethingToConceptId($referenceUNID, $system);

        if ($conceptLinkConcept != '')
            $conceptLinkConcept = CommonFunctions::somethingToConceptId($conceptLinkConcept, $system);

        if ($conceptTargetConcept != '')
            $conceptTargetConcept = CommonFunctions::somethingToConceptId($conceptTargetConcept, $system);

        if ($referenceUNID != 'IS NOT NULL')
            $referenceUNID = CommonFunctions::somethingToConceptId($referenceUNID, $system);


        $pdo = $system->getConnection();


        $tableLink = $system->linkTable;
        $i = 0;
        $bindParamArray = array();

        //we are building an OR statement if the re are different value to search
        if (is_array($valueToSearch)) {
            if (empty($valueToSearch)) return array();

            $initialStatement = true;
            $orStatement = '';


            foreach ($valueToSearch as $uniqueValue) {

                if (!$initialStatement) {
                    $orStatement .= " OR value = :value_$i";
                    $bindParamArray[":value_$i"] = $uniqueValue;
                }
                $initialStatement = 0;
                $i++;
            }

            $valueToSearchStatement = "(value = :value_0" . $orStatement . ")";
            $bindParamArray[":value_0"] = $valueToSearch[0];
        } else if ($valueToSearch === true) {
            $valueToSearchStatement = 'value IS NOT NULL';
        } else {
            $valueToSearchStatement = "value = :value_$i";
            $bindParamArray[":value_$i"] = $valueToSearch;
        }


        if ($conceptLinkConcept) {
            $linkConceptSQL = "AND $tableLink.idConceptLink = :linkConcept";
            $bindParamArray[':linkConcept'] = [(int)$conceptLinkConcept, PDO::PARAM_INT];
        }

        if ($conceptTargetConcept) {
            $targetConceptSQL = "AND $tableLink.idConceptTarget = :targetConcept";
            $bindParamArray[':targetConcept'] = [(int)$conceptTargetConcept, PDO::PARAM_INT];
        }

        if ($limit && is_numeric($limit)) {
            $limitSQL = "LIMIT " . (int)$limit;
        }

        if ($random) {
            $randomFunc = self::$driver !== null ? self::$driver->getRandomOrderSQL() : 'RAND()';
            $randomSQL = "ORDER BY $randomFunc";
        }

        $deletedUNID = (int)$system->deletedUNID;
        if ($referenceUNID == 'IS NOT NULL') {
            $refUNIDSQL = "AND `$tableReference`.idConcept IS NOT NULL";
        } else {
            $refUNIDSQL = "AND `$tableReference`.idConcept = :refUNID";
            $bindParamArray[':refUNID'] = [(int)$referenceUNID, PDO::PARAM_INT];
        }

        $sql = " SELECT * FROM `$tableReference`, $tableLink
            WHERE $valueToSearchStatement
            $refUNIDSQL
            AND `$tableLink`.flag != :deletedFlag
            AND `$tableReference`.linkReferenced = $tableLink.id
            $linkConceptSQL $targetConceptSQL $randomSQL $limitSQL ";
        $bindParamArray[':deletedFlag'] = [$deletedUNID, PDO::PARAM_INT];

        $results = QueryExecutor::fetchAll($pdo, $sql, $bindParamArray);

        if ($results === null) {
            return null;
        }

        $array = null;

        foreach ($results as $result) {
            $conceptStart = $result['idConceptStart'];
            //do we want concept
            if ($advanced == true) {
                $buildArray['idConceptStart'] = $conceptStart;
                $buildArray['referenceConcept'] = $result['idConcept'];
                $buildArray['entityId'] = $result['id'];
                $buildArray['referenceValue'] = $result['value'];
                $array[] = $buildArray;
            } else {
                $array[] = $conceptStart;
            }
        }

        return $array;

    }

    /**
     * Search for entity concept IDs by reference value with comparison operator.
     * Returns an array of idConceptStart values matching the criteria.
     *
     * @param System $system
     * @param string $refShortname Reference shortname (e.g. 'name', 'price')
     * @param string $operator SQL operator: =, !=, >, >=, <, <=, LIKE
     * @param mixed $value Value to compare against
     * @param string $conceptLinkConcept Factory's entityReferenceContainer concept ID
     * @param string $conceptTargetConcept Factory's entityContainedIn concept ID
     * @param int|null $limit Optional result limit
     * @return array|null Array of concept IDs or null
     */
    public static function searchConceptByRef(
        System $system,
        string $refShortname,
        string $operator,
        mixed $value,
        $conceptLinkConcept = '',
        $conceptTargetConcept = '',
        ?int $limit = null
    ): ?array {
        $allowedOperators = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];
        $operator = strtoupper($operator);
        if (!in_array($operator, $allowedOperators, true)) {
            return null;
        }

        $pdo = $system->getConnection();
        $tableReference = $system->tableReference;
        $tableLink = $system->linkTable;
        $deletedUNID = (int)$system->deletedUNID;

        $refConceptId = CommonFunctions::somethingToConceptId($refShortname, $system);
        if (!$refConceptId) {
            return null;
        }

        $bindParamArray = [];
        $bindParamArray[':refConcept'] = [(int)$refConceptId, \PDO::PARAM_INT];
        $bindParamArray[':deletedFlag'] = [$deletedUNID, \PDO::PARAM_INT];
        $bindParamArray[':searchValue'] = $value;

        // For numeric comparison operators, CAST the varchar value column
        $numericOperators = ['>', '>=', '<', '<='];
        if (in_array($operator, $numericOperators, true)) {
            $castCol = self::$driver !== null
                ? self::$driver->getCastNumericSQL('value')
                : 'CAST(value AS DECIMAL)';
            $valueCondition = "$castCol $operator :searchValue";
        } else {
            $valueCondition = "value $operator :searchValue";
        }

        $linkConceptSQL = '';
        if ($conceptLinkConcept !== '') {
            $linkConceptId = is_numeric($conceptLinkConcept)
                ? (int)$conceptLinkConcept
                : (int)CommonFunctions::somethingToConceptId($conceptLinkConcept, $system);
            if ($linkConceptId) {
                $linkConceptSQL = "AND $tableLink.idConceptLink = :linkConcept";
                $bindParamArray[':linkConcept'] = [$linkConceptId, \PDO::PARAM_INT];
            }
        }

        $targetConceptSQL = '';
        if ($conceptTargetConcept !== '') {
            $targetConceptId = is_numeric($conceptTargetConcept)
                ? (int)$conceptTargetConcept
                : (int)CommonFunctions::somethingToConceptId($conceptTargetConcept, $system);
            if ($targetConceptId) {
                $targetConceptSQL = "AND $tableLink.idConceptTarget = :targetConcept";
                $bindParamArray[':targetConcept'] = [$targetConceptId, \PDO::PARAM_INT];
            }
        }

        $limitSQL = '';
        if ($limit !== null) {
            $limitSQL = "LIMIT " . (int)$limit;
        }

        $sql = "SELECT DISTINCT $tableLink.idConceptStart FROM `$tableReference`
            INNER JOIN $tableLink ON `$tableReference`.linkReferenced = $tableLink.id
            WHERE $valueCondition
            AND `$tableReference`.idConcept = :refConcept
            AND $tableLink.flag != :deletedFlag
            $linkConceptSQL $targetConceptSQL $limitSQL";

        $results = QueryExecutor::fetchAll($pdo, $sql, $bindParamArray);

        if ($results === null) {
            return null;
        }

        $array = [];
        foreach ($results as $result) {
            $array[] = $result['idConceptStart'];
        }

        return $array;
    }

    /**
     * Search concept IDs by a structured multi-filter query on references.
     *
     * Unlike searchConceptByRef (single filter), this joins the References table
     * once per filter (aliased R0, R1, ...) so filters on different ref fields
     * can be combined with AND semantics in a single SQL round-trip.
     *
     * @param System $system
     * @param array<int, array{ref: string, op: string, value: mixed}> $filters
     *        Each filter: ref = reference shortname, op in =,!=,>,>=,<,<=,LIKE,IN,
     *        value = scalar (or array for IN). Numeric ops cast value column.
     * @param string $conceptLinkConcept Factory's entityReferenceContainer concept
     * @param string $conceptTargetConcept Factory's entityContainedIn concept
     * @param array{ref: string, direction?: string, numeric?: bool}|null $sort
     *        Optional ORDER BY on one of the filter refs (or any ref).
     *        direction defaults to ASC, numeric false = lexicographic.
     * @param int|null $limit
     * @param int|null $offset
     * @return array<int, int>|null Ordered list of idConceptStart, or null on error
     */
    public static function searchConceptByRefQuery(
        System $system,
        array $filters,
        $conceptLinkConcept = '',
        $conceptTargetConcept = '',
        ?array $sort = null,
        ?int $limit = null,
        ?int $offset = null
    ): ?array {
        if (empty($filters)) {
            return null;
        }

        $allowedOps = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN'];
        $numericOps = ['>', '>=', '<', '<='];

        $pdo = $system->getConnection();
        $tableReference = $system->tableReference;
        $tableLink = $system->linkTable;
        $deletedUNID = (int)$system->deletedUNID;

        $bindParamArray = [];
        $bindParamArray[':deletedFlag'] = [$deletedUNID, \PDO::PARAM_INT];

        $joinSQL = '';
        $whereSQL = '';
        // Map ref shortname → alias, so sort can reuse an existing join
        $refAlias = [];

        foreach ($filters as $i => $f) {
            $refName = $f['ref'] ?? null;
            $op      = strtoupper((string)($f['op'] ?? '='));
            $value   = $f['value'] ?? null;

            if (!$refName || !in_array($op, $allowedOps, true)) {
                return null;
            }
            $refConceptId = CommonFunctions::somethingToConceptId($refName, $system);
            if (!$refConceptId) {
                return null;
            }

            $alias = "R{$i}";
            $refAlias[$refName] = $alias;

            $bindParamArray[":refConcept_{$i}"] = [(int)$refConceptId, \PDO::PARAM_INT];
            $joinSQL .= " INNER JOIN `$tableReference` $alias "
                      . " ON $alias.linkReferenced = $tableLink.id "
                      . " AND $alias.idConcept = :refConcept_{$i} ";

            if ($op === 'IN') {
                if (!is_array($value) || empty($value)) {
                    return null;
                }
                $placeholders = [];
                foreach (array_values($value) as $j => $v) {
                    $ph = ":inVal_{$i}_{$j}";
                    $placeholders[] = $ph;
                    $bindParamArray[$ph] = $v;
                }
                $whereSQL .= " AND $alias.value IN (" . implode(',', $placeholders) . ") ";
            } elseif (in_array($op, $numericOps, true)) {
                $castCol = self::$driver !== null
                    ? self::$driver->getCastNumericSQL("$alias.value")
                    : "CAST($alias.value AS DECIMAL)";
                $bindParamArray[":val_{$i}"] = $value;
                $whereSQL .= " AND $castCol $op :val_{$i} ";
            } else {
                $bindParamArray[":val_{$i}"] = $value;
                $whereSQL .= " AND $alias.value $op :val_{$i} ";
            }
        }

        $linkConceptSQL = '';
        if ($conceptLinkConcept !== '') {
            // Resolve shortname → concept id (callers pass 'contained_in_file' etc.)
            $linkConceptId = is_numeric($conceptLinkConcept)
                ? (int)$conceptLinkConcept
                : (int)CommonFunctions::somethingToConceptId($conceptLinkConcept, $system);
            if ($linkConceptId) {
                $linkConceptSQL = "AND $tableLink.idConceptLink = :linkConcept";
                $bindParamArray[':linkConcept'] = [$linkConceptId, \PDO::PARAM_INT];
            }
        }
        $targetConceptSQL = '';
        if ($conceptTargetConcept !== '') {
            $targetConceptId = is_numeric($conceptTargetConcept)
                ? (int)$conceptTargetConcept
                : (int)CommonFunctions::somethingToConceptId($conceptTargetConcept, $system);
            if ($targetConceptId) {
                $targetConceptSQL = "AND $tableLink.idConceptTarget = :targetConcept";
                $bindParamArray[':targetConcept'] = [$targetConceptId, \PDO::PARAM_INT];
            }
        }

        // Optional ORDER BY — reuse existing join alias if sort ref is already filtered,
        // otherwise add a LEFT JOIN so entities without that ref still appear (null last)
        $orderSQL = '';
        if ($sort !== null && !empty($sort['ref'])) {
            $sortRef = $sort['ref'];
            $direction = strtoupper($sort['direction'] ?? 'ASC');
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                $direction = 'ASC';
            }
            $numeric = !empty($sort['numeric']);

            if (isset($refAlias[$sortRef])) {
                $sortAlias = $refAlias[$sortRef];
            } else {
                $sortConceptId = CommonFunctions::somethingToConceptId($sortRef, $system);
                if ($sortConceptId) {
                    $sortAlias = 'RS';
                    $bindParamArray[':sortRefConcept'] = [(int)$sortConceptId, \PDO::PARAM_INT];
                    $joinSQL .= " LEFT JOIN `$tableReference` $sortAlias "
                              . " ON $sortAlias.linkReferenced = $tableLink.id "
                              . " AND $sortAlias.idConcept = :sortRefConcept ";
                } else {
                    $sortAlias = null;
                }
            }

            if ($sortAlias !== null) {
                $col = "$sortAlias.value";
                if ($numeric) {
                    $col = self::$driver !== null
                        ? self::$driver->getCastNumericSQL($col)
                        : "CAST($col AS DECIMAL)";
                }
                $orderSQL = " ORDER BY $col $direction ";
            }
        }

        $limitSQL = '';
        if ($limit !== null) {
            $limitSQL = " LIMIT " . (int)$limit;
            if ($offset !== null && $offset > 0) {
                $limitSQL .= " OFFSET " . (int)$offset;
            }
        }

        $sql = "SELECT DISTINCT $tableLink.idConceptStart FROM $tableLink
                $joinSQL
                WHERE $tableLink.flag != :deletedFlag
                $linkConceptSQL $targetConceptSQL
                $whereSQL
                $orderSQL $limitSQL";

        $results = QueryExecutor::fetchAll($pdo, $sql, $bindParamArray);
        if ($results === null) {
            return null;
        }

        $out = [];
        foreach ($results as $row) {
            $out[] = (int)$row['idConceptStart'];
        }
        return $out;
    }

    /**
     * Execute an arbitrary SQL statement.
     *
     * @param string $sql The SQL query
     * @param array|null $bindParamArray Parameters to bind
     * @param bool $autocommit If false, wraps in a transaction
     * @param System|null $system Optional system instance for connection
     */
    public static function executeSQL($sql, $bindParamArray = null, $autocommit = true, ?System $system = null)
    {

        $pdo = $system ? $system->getConnection() : System::$pdo->get();

        $opened = false;
        if ($autocommit == false) {
            TransactionManager::begin($pdo);
            $opened = true;
        }

        QueryExecutor::execute($pdo, $sql, is_array($bindParamArray) ? $bindParamArray : []);

        if ($opened) {
            TransactionManager::commit();
        }
    }

    public static function commit()
    {
        TransactionManager::commit();
    }

    /**
     * Get memory allocation for given tables.
     *
     * @param array $tables Array of table names.
     * @param string $schema Database name
     *
     * @return array
     */
    public static function getAllocatedMemory(array $tables = [], string $schema = "", ?System $system = null): array
    {
        if (count($tables) == 0) return [];

        $pdo = $system ? $system->getConnection() : System::$pdo->get();

        $params = [];
        $placeholders = [];
        foreach ($tables as $i => $table) {
            $key = ":table_$i";
            $placeholders[] = $key;
            $params[$key] = $table;
        }
        $placeholderStr = implode(",", $placeholders);

        $sql = "SELECT table_name,
                ROUND(((data_length + index_length)), 2) AS 'bytes'
                FROM information_schema.TABLES as iSchema
                where iSchema.table_name in ($placeholderStr) and
                iSchema.table_schema = :schema";
        $params[':schema'] = $schema;

        return QueryExecutor::fetchAll($pdo, $sql, $params) ?? [];

    }
}
