<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:22
 */

namespace SandraCore;

use PDO;
use PDOException;


class DatabaseAdapter
{

    private static $transactionStarted = false;
    private static $pdo;

    public static function getSujectConcept()
    {
    }

    public static function rawCreateReference($tripletId, $conceptId, $value, System $system, $autocommit = true)
    {

        if (!isset($value)) {
            return;
        }

        $pdo = System::$pdo->get();

        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        $targetTable = $system->tableReference;

        $sql = "INSERT INTO `$targetTable` 
                (idConcept, linkReferenced, value) VALUES ($conceptId, $tripletId, :value)
                ON DUPLICATE KEY UPDATE  value = :value, id=LAST_INSERT_ID(id)";

        //do we reach the max column data
        if (strlen($value) > 255)
            $value = substr($value, 0, 255);

        $start = microtime(true);

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->bindParam(":value", $value, PDO::PARAM_STR, 50);
            $pdoResult->execute();

        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $pdo->lastInsertId();

    }

    public static function rawCreateTriplet($conceptSubject, $conceptVerb, $conceptTarget, System $system, $udateOnExistingLK = 0, $autocommit = true)
    {

        $pdo = System::$pdo->get();

        $tableLink = $system->linkTable;

        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        $updateID = null;

        //if the link is existing and we try to update it instead of adding a new. For example card - set rarity - rare and we want to change the rarity
        //and not add a new link
        if ($udateOnExistingLK == 1) {

            $sql = "SELECT id FROM $tableLink 
                    WHERE idConceptStart = $conceptSubject AND idConceptLink = $conceptVerb AND flag != $system->deletedUNID";

            $start = microtime(true);

            $result = $pdo->query($sql);

            System::$sandraLogger->query($sql, microtime(true) - $start);

            $rows = $result->fetchAll(PDO::FETCH_ASSOC);

            if ($rows) {
                $lastRow = end($rows);
                $updateID = $lastRow['id'];

                $sql = "UPDATE $tableLink SET idConceptTarget = $conceptTarget  WHERE id = $updateID";
                $start = microtime(true);

                try {
                    $result = $pdo->query($sql);
                } catch (PDOException $exception) {
                    System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
                    System::sandraException($exception);
                    return;
                }

                System::$sandraLogger->query($sql, microtime(true) - $start);
                return $updateID;

            }
        }


        $sql = "INSERT INTO $tableLink (idConceptStart ,idConceptLink ,idConceptTarget,flag) VALUES ('$conceptSubject', '$conceptVerb', '$conceptTarget',0) ON DUPLICATE KEY UPDATE flag = 0, id=LAST_INSERT_ID(id)";

        $start = microtime(true);

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $pdo->lastInsertId();

    }

    public static function setStorage(Entity $entity, $value, $autocommit = true)
    {

        $pdo = System::$pdo->get();
        $tableStorage = $entity->system->tableStorage;

        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        $sql = "INSERT INTO $tableStorage (linkReferenced ,`value` ) VALUES (:linkId,  :storeValue) ON DUPLICATE KEY UPDATE value = :storeValue";
        $start = microtime(true);

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->bindParam(':storeValue', $value, PDO::PARAM_STR);
            $pdoResult->bindParam(":linkId", $entity->entityId, PDO::PARAM_INT);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return null;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $value;

    }

    public static function getStorage(Entity $entity)
    {

        $pdo = System::$pdo->get();
        $tableStorage = $entity->system->tableStorage;

        $sql = "SELECT `value` from $tableStorage WHERE linkReferenced = " . $entity->entityId . " LIMIT 1";
        $start = microtime(true);


        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::sandraException($exception);
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            return;
        }

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC);
        System::$sandraLogger->query($sql, microtime(true) - $start);

        $value = null;

        foreach ($results as $result) {
            $value = $result['value'];
        }

        return $value;

    }

    public static function rawFlag(Entity $entity, Concept $flag, System $system, $autocommit = true)
    {

        $pdo = System::$pdo->get();
        $tableLink = $system->linkTable;

        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        $sql = "UPDATE $tableLink SET flag = $flag->idConcept  WHERE id = $entity->entityId";
        $start = microtime(true);

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

    }

    public static function rawCreateConcept($code, System $system, $autocommit = true)
    {

        $pdo = System::$pdo->get();
        $tableConcept = $system->conceptTable;
        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        $sql = "INSERT INTO $tableConcept (code) VALUES ('$code');";

        $start = microtime(true);

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $pdo->lastInsertId();

    }

    public static function rawGetTriplets(System $system, $conceptStart, $conceptLink = 0, $conceptTarget = 0, $getIds = 0, $su = 0)
    {
        // Force into Super User
        $su = 1;

        $pdo = System::$pdo->get();
        $tableLink = $system->linkTable;

        if ($conceptLink != 0)
            $linkSQL = " AND idConceptLink = $conceptLink";
        else
            $linkSQL = "";

        if ($conceptTarget != 0)
            $targetSQL = " AND idConceptTarget = $conceptTarget";
        else
            $targetSQL = "";

        //hide links for non Super users
        //        if ($su == 0) {
        //            $hideLinks = "AND
        //                        (
        //                        idConceptLink IN(SELECT idConceptStart FROM $tableLink WHERE idConceptTarget IN($comma_separated) AND idConceptLink IN ($includeCid, $containsInFileCid ) )
        //                        OR idConceptLink NOT IN ( SELECT idConceptStart FROM `$tableLink` WHERE idConceptLink IN ($includeCid, $containsInFileCid) )
        //                        )";
        //        } else
        //             $hideLinks = '';

        $hideLinks = '';

        $sql = "SELECT * FROM `$tableLink` WHERE  idConceptStart = $conceptStart" . $linkSQL . $targetSQL .
            " AND flag = 0 " . $hideLinks;
        $start = microtime(true);


        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::sandraException($exception);
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            return;
        }

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC);

        System::$sandraLogger->query($sql, microtime(true) - $start);

        $value = null;
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

    public static function searchConcept(System $system, $valueToSearch, $referenceUNID = 'IS NOT NULL', $conceptLinkConcept = '',
                                                $conceptTargetConcept = '', $limit = '', $random = '', $advanced = null)
    {
        $limitSQL = '';
        $randomSQL = '';
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


        $pdo = System::$pdo->get();


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
                    $bindParamArray["value_$i"] = $uniqueValue;
                }
                $initialStatement = 0;
                $i++;
            }

            $valueToSearchStatement = "(value = :value_0" . $orStatement . ")";
            $bindParamArray["value_0"] = $valueToSearch[0];
        } else if ($valueToSearch === true) {
            $valueToSearchStatement = 'value IS NOT NULL';
        } else {
            //$valueToSearch = mysqli_real_escape_string($dbLink, $valueToSearch);
            $valueToSearchStatement = "value = :value_$i";
            $bindParamArray["value_$i"] = $valueToSearch;
        }


        if ($referenceUNID == 'IS NOT NULL') {
            $equalSeparator = '';
        } else {
            $equalSeparator = '=';
        } //This fix is because the request says references.idConcept = IS NOT NULL raising an sql error. When is not null the equal need to be striped

        if ($conceptLinkConcept) {
            $linkConceptSQL = "AND $tableLink.idConceptLink =  $conceptLinkConcept";
        }

        if ($conceptTargetConcept) {
            $targetConceptSQL = "AND $tableLink.idConceptTarget =  $conceptTargetConcept";
        }

        if ($limit && is_numeric($limit)) {
            $limitSQL = "LIMIT $limit";
        }

        if ($random) {
            $randomSQL = 'ORDER BY RAND()';
        }

        $sql = " SELECT * FROM `$tableReference`, $tableLink 
            WHERE $valueToSearchStatement 
            AND `$tableReference`.idConcept $equalSeparator $referenceUNID
            AND `$tableLink`.flag != $system->deletedUNID
            AND `$tableReference`.linkReferenced = $tableLink.id 
            $linkConceptSQL $targetConceptSQL $randomSQL $limitSQL ";

        $start = microtime(true);

        try {

            $pdoResult = $pdo->prepare($sql);
            foreach ($bindParamArray as $key => &$value) {
                $pdoResult->bindParam("$key", $value, PDO::PARAM_STR);
            }
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return null;
        }

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC);

        System::$sandraLogger->query($sql, microtime(true) - $start);

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

    public static function executeSQL($sql, $bindParamArray = null, $autocommit = true)
    {

        $pdo = System::$pdo->get();

        System::$sandraLogger->query("Auto commit " . (($autocommit) ? 'On' : 'Off'), 0);

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction ", 0);
            self::$pdo = $pdo;
            self::$transactionStarted = true;
        }

        try {

            $pdoResult = $pdo->prepare($sql);

            if (is_array($bindParamArray)) {
                foreach ($bindParamArray as $key => &$value) {
                    $pdoResult->bindParam("$key", $value, PDO::PARAM_STR);
                }
            }

            $start = microtime(true);

            $pdoResult->execute();

        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return null;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

    }

    public static function commit()
    {
        System::$sandraLogger->query("Commit ", 0);
        self::$pdo->commit();
        self::$transactionStarted = false;
    }

    /**
     * Get memory allocation for given tables.
     *
     * @param array $tables Array of table names.
     * @param string $schema Database name
     *
     * @return array
     */
    public static function getAllocatedMemory(array $tables = [], string $schema): array
    {
        if (count($tables) == 0) return [];

        $pdo = System::$pdo->get();
        $tables_str = implode("','", $tables);

        $sql = "SELECT table_name,                
                ROUND(((data_length + index_length)), 2) AS 'bytes'
                FROM information_schema.TABLES as iSchema
                where iSchema.table_name in ('$tables_str') and
                iSchema.table_schema = '$schema'";

        $start = microtime(true);

        $result = $pdo->query($sql);

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $result->fetchAll(PDO::FETCH_ASSOC);

    }
}

