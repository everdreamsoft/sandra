<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:22
 */

namespace SandraCore;
use PDOException ;
use PDO;


class DatabaseAdapter{

    private static $transactionStarted = false ;
    private static $pdo ;


    public static function getSujectConcept(){






    }

    public static function rawCreateReference($tripletId,$conceptId,$value, System $system,$autocommit = true){

        if (!isset($value)){

            return ;
        }

        $pdo = System::$pdo->get();


        if (!self::$transactionStarted && $autocommit == false){
            $pdo->beginTransaction();
            self::$pdo = $pdo ;
            self::$transactionStarted = true ;


        }

        $targetTable = $system->tableReference ;

        $sql = "INSERT INTO `$targetTable` (idConcept, linkReferenced, value) VALUES ($conceptId, $tripletId, :value)
        ON DUPLICATE KEY UPDATE  value = :value, id=LAST_INSERT_ID(id)";



        //do we reach the max column data
        if (strlen($value) > 255)
            $value = substr($value, 0, 255) ;




        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->bindParam(":value", $value, PDO::PARAM_STR, 50);
            System::logDatabaseStart($sql);
            $pdoResult->execute();

        }
        catch(PDOException $exception){
            //die("too havy");
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);
            return ;
        }

        System::logDatabaseEnd($sql);



        return $pdo->lastInsertId();


    }

    public static function rawCreateTriplet($conceptSubject, $conceptVerb,$conceptTarget,System $system,$udateOnExistingLK = 0,$autocommit = true){

        $pdo = System::$pdo->get();

        $tableLink = $system->linkTable ;

        if (!self::$transactionStarted && $autocommit == false){
            $pdo->beginTransaction();
            self::$pdo = $pdo ;
            self::$transactionStarted = true ;


        }

        $updateID = null;

//if the link is existing and we try to update it instead of adding a new. For example card - set rarity - rare and we want to change the rarity
//and not add a new link
        if ($udateOnExistingLK == 1) {


            $sql = "SELECT id FROM $tableLink WHERE idConceptStart = $conceptSubject AND idConceptLink = $conceptVerb AND flag != $system->deletedUNID";

            $result = $pdo->query($sql);

            $rows = $result->fetchAll(PDO::FETCH_ASSOC);

            //$updateID = $row['id'];

            if ($rows) {
                $lastRow = end($rows);
                $updateID = $lastRow['id'];

                $sql = "UPDATE $tableLink SET idConceptTarget = $conceptTarget  WHERE id = $updateID";


                try {
                    $result = $pdo->query($sql);
                } catch (PDOException $exception) {
                    System::logDatabaseEnd($exception->getMessage());
                    System::sandraException($exception);
                    return;
                }


                return $updateID;
            }

        }



        $sql = "INSERT INTO $tableLink (idConceptStart ,idConceptLink ,idConceptTarget,flag) VALUES ('$conceptSubject', '$conceptVerb', '$conceptTarget',0) ON DUPLICATE KEY UPDATE flag = 0, id=LAST_INSERT_ID(id)";


        try {
            $pdoResult = $pdo->prepare($sql);
            System::logDatabaseStart($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);
            return ;
        }

        System::logDatabaseEnd($sql);

        return $pdo->lastInsertId();


    }


    public static function setStorage(Entity $entity,$value){


        $pdo = System::$pdo->get();
        $tableStorage = $entity->system->tableStorage ;




        $sql = "INSERT INTO $tableStorage (linkReferenced ,`value` ) VALUES (:linkId,  :storeValue) ON DUPLICATE KEY UPDATE  linkReferenced=LAST_INSERT_ID(linkReferenced)";




        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->bindParam(':storeValue', $value, PDO::PARAM_STR);
            $pdoResult->bindParam(":linkId", $entity->entityId, PDO::PARAM_INT);
            System::logDatabaseStart($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){

            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);

            return null ;
        }

        System::logDatabaseEnd($sql);

        return $value ;



    }

    public static function getStorage(Entity $entity){


        $pdo = System::$pdo->get();
        $tableStorage = $entity->system->tableStorage ;




        $sql = "SELECT `value` from $tableStorage WHERE linkReferenced = ".$entity->entityId ." LIMIT 1";


        try {
            $pdoResult = $pdo->prepare($sql);
            System::logDatabaseStart($sql);
            $pdoResult->execute();

        }
        catch(PDOException $exception){

            System::sandraException($exception);
            System::logDatabaseEnd($exception->getMessage());
            return ;
        }

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC) ;
        System::logDatabaseEnd($sql);


        $value = null ;
        foreach ($results as $result) {

            $value = $result['value'];

        }

        return $value ;



    }


    public static function rawFlag(Entity $entity, Concept $flag, System $system, $autocommit = true)
    {


        $pdo = System::$pdo->get();

        $tableLink = $system->linkTable;

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
            self::$pdo = $pdo;
            self::$transactionStarted = true;


        }


        $sql = "UPDATE $tableLink SET flag = $flag->idConcept  WHERE id = $entity->entityId";

        try {
            $pdoResult = $pdo->prepare($sql);
            System::logDatabaseStart($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);
            return;
        }

        System::logDatabaseEnd($sql);


    }

    public static function rawCreateConcept($code, System $system,$autocommit = true){


        $pdo = System::$pdo->get();
        $tableConcept = $system->conceptTable ;

        if (!self::$transactionStarted && $autocommit == false){
            $pdo->beginTransaction();
            self::$pdo = $pdo ;
            self::$transactionStarted = true ;

        }


        $sql = "INSERT INTO $tableConcept (code) VALUES ('$code');";

        try {
            $pdoResult = $pdo->prepare($sql);
            System::logDatabaseStart($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);

            return ;
        }

        System::logDatabaseEnd($sql);


        return $pdo->lastInsertId();


    }

    public static function rawGetTriplets(System $system,$conceptStart, $conceptLink = 0, $conceptTarget = 0, $getIds = 0, $su = 0)
    {

        //force into super user
        $su = 1;

        $pdo = System::$pdo->get();
        $tableLink = $system->linkTable;


        //$comma_separated = implode(",", $_SESSION['accessToFiles']);


//echoln($comma_separated.'comme separated');


        if ($conceptLink != 0)
            $linkSQL = " AND idConceptLink = $conceptLink";
        else
            $linkSQL = "";

        if ($conceptTarget != 0)
            $targetSQL = " AND idConceptTarget = $conceptTarget";
        else
            $targetSQL = "";

//hide links for non Super users
        if ($su == 0) {
            $hideLinks = "AND 
(
idConceptLink IN(SELECT idConceptStart FROM $tableLink WHERE idConceptTarget IN($comma_separated) AND idConceptLink IN ($includeCid, $containsInFileCid ) ) 
OR idConceptLink NOT IN ( SELECT idConceptStart FROM `$tableLink` WHERE idConceptLink IN ($includeCid, $containsInFileCid) )
)";
        } else
            $hideLinks = '';


        $sql = "SELECT * FROM `$tableLink` WHERE  idConceptStart = $conceptStart" . $linkSQL . $targetSQL .
            " AND flag = 0 
" . $hideLinks;


        try {
            $pdoResult = $pdo->prepare($sql);
            System::logDatabaseStart($sql);
            $pdoResult->execute();

        } catch (PDOException $exception) {

            System::sandraException($exception);
            System::logDatabaseEnd($exception->getMessage());
            return;
        }

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC);
        System::logDatabaseEnd($sql);


        $value = null;
        $array = array();
        foreach ($results as $result) {

            $idLink = $result['id'];
            $idConceptTarget = $result['idConceptTarget'];

            $link = $result['idConceptLink'];
            if ($getIds) {

                $array[$link][]['value'] = $idConceptTarget;

//get the last insterted key
                $keys = array_keys($array[$link]);
                $key = end($keys);

                $array[$link][$key]['linkId'] = $idLink;
            } else {
                $array[$link][] = $idConceptTarget;
            }

        }

        return $array;

    }


    public static function searchConcept($valueToSearch, $referenceUNID = 'IS NOT NULL', System $system, $conceptLinkConcept = '',
                                         $conceptTargetConcept = '', $limit = '', $random = '', $advanced = null){
        $limitSQL = '';
        $randomSQL = '';
        $targetConceptSQL = '';
        $linkConceptSQL = '' ;
        $randomSQL = '' ;
        $limitSQL = '' ;
        $tableReference = $system->tableReference ;

        if ($conceptLinkConcept != '')
            $conceptLinkConcept = CommonFunctions::somethingToConceptId($conceptLinkConcept, $system);

        if ($conceptTargetConcept != '')
            $conceptTargetConcept = CommonFunctions::somethingToConceptId($conceptTargetConcept, $system);



        $pdo = System::$pdo->get();

        if (!$referenceUNID) $referenceUNID = 'IS NOT NULL';

        


        $tableLink = $system->linkTable ;
        $i=0;

        //we are building an OR statement if the re are different value to search
        if (is_array($valueToSearch)) {
            if (empty($valueToSearch)) return array();

            $initialStatement = true;
            $orStatement = '';


            foreach ($valueToSearch as $uniqueValue) {

                if (!$initialStatement) {
                    $orStatement .= " OR value = :value_$i";
                    $bindParamArray["value_$i"] = $uniqueValue ;
                }
                $initialStatement = 0;
                $i++;
            }

            $valueToSearchStatement =  "(value = :value_0".$orStatement  .")";
            $bindParamArray["value_0"] = $valueToSearch[0] ;
        } else {
            //$valueToSearch = mysqli_real_escape_string($dbLink, $valueToSearch);
            $valueToSearchStatement = "value = :value_$i";
            $bindParamArray["value_$i"] = $valueToSearch ;
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

        $sql = "
	
	SELECT * FROM `$tableReference`, $tableLink 
	WHERE $valueToSearchStatement 
	AND `$tableReference`.idConcept $equalSeparator $referenceUNID
	AND `$tableLink`.flag != $system->deletedUNID
	AND `$tableReference`.linkReferenced = $tableLink.id 
	$linkConceptSQL $targetConceptSQL $randomSQL $limitSQL 
	
	";





        try {
            $pdoResult = $pdo->prepare($sql);

            foreach ($bindParamArray as $key => &$value){

                $pdoResult->bindParam("$key", $value, PDO::PARAM_STR);

            }


            System::logDatabaseStart($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);
            return null ;
        }
        System::logDatabaseEnd($sql);

        $results = $pdoResult->fetchAll(PDO::FETCH_ASSOC) ;

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

        if (!self::$transactionStarted && $autocommit == false) {
            $pdo->beginTransaction();
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


            System::logDatabaseStart($sql);
            $pdoResult->execute();
        } catch (PDOException $exception) {
            System::logDatabaseEnd($exception->getMessage());
            System::sandraException($exception);
            return null;
        }
        System::logDatabaseEnd($sql);


    }



    public static function commit(){

        self::$pdo->commit() ;
        self::$transactionStarted = false ;



    }





}

