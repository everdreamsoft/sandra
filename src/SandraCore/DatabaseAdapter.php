<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:22
 */

namespace SandraCore;
use PDOException ;


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





        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute(array(':value' => $value));
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }





        return $pdo->lastInsertId();


    }

    public static function rawCreateTriplet($conceptSubject, $conceptVerb,$conceptTarget,System $system,$udateOnExistingLK = 0){

        $pdo = System::$pdo->get();

        $tableLink = $system->linkTable ;

        $updateID = null;

//if the link is existing and we try to update it instead of adding a new. For example card - set rarity - rare and we want to change the rarity
//and not add a new link
        if ($udateOnExistingLK == 1) {
            echo('updating');

            $sql = "SELECT id FROM $tableLink WHERE idConceptStart = $conceptSubject AND idConceptLink = $conceptVerb AND flag != $deletedUNID";

            $result = $pdo->query($sql);

            $row = $result->fetchAll(PDO::FETCH_ASSOC) ;

            $updateID = $row['id'];
        }

        if ($updateID) {

            $sql = "UPDATE $tableLink SET idConceptTarget = $conceptTarget  WHERE id = $updateID";


            try {
                $result = $pdo->query($sql);
            }
            catch(PDOException $exception){

                System::sandraException($exception);
                return ;
            }


            return $updateID;
        }

        $sql = "INSERT INTO $tableLink (idConceptStart ,idConceptLink ,idConceptTarget,flag) VALUES ('$conceptSubject', '$conceptVerb', '$conceptTarget',0) ON DUPLICATE KEY UPDATE flag = 0, id=LAST_INSERT_ID(id)";


        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }



        return $pdo->lastInsertId();


    }

    public static function rawCreateConcept($code, System $system){


        $pdo = System::$pdo->get();
        $tableConcept = $system->conceptTable ;

            $sql = "INSERT INTO $tableConcept (code) VALUES ('$code');";

        try {
            $pdoResult = $pdo->prepare($sql);
            $pdoResult->execute();
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }


        return $pdo->lastInsertId();


    }


    public static function commit(){

       self::$pdo->commit() ;
        self::$transactionStarted = false ;



    }





}

