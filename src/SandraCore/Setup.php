<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 16:25
 */

namespace SandraCore;


class Setup
{

    public static function flushDatagraph(System $system){



        $sql = "DROP TABLE $system->conceptTable";

        System::$pdo->get()->query($sql);

        $sql="DROP TABLE $system->linkTable";


        System::$pdo->get()->query($sql);


        $sql="DROP TABLE  $system->tableReference";
        System::$pdo->get()->query($sql);


        $sql="DROP TABLE  $system->tableConf";
        System::$pdo->get()->query($sql);

        $sql="DROP TABLE  $system->tableStorage";
        System::$pdo->get()->query($sql);






    }

    public function setupHumanReadableView(System $system){
        //todo execute and adapt tables names

        $sql = "SELECT 
t.id AS entity_id,
IF(c1.shortname IS NULL,CONCAT(t.`idConceptStart`, ': ',c1.code),c1.shortname) 
             AS Subject,
IF(c2.shortname IS NULL,CONCAT(t.`idConceptLink`, ': ',c2.code),CONCAT(t.`idConceptLink`, ' : ',c2.shortname)) 
             AS Verb,
             IF(c3.shortname IS NULL,CONCAT(t.`idConceptTarget`, ' :',c3.code),CONCAT(t.`idConceptTarget`, '  : ', c3.shortname) )
             AS Target,
             
             IF(c4.shortname IS NULL,CONCAT(t.`idConceptTarget`, ':',c4.code),CONCAT(t.`flag`, ': ', c4.shortname) )
             AS Flag  
  FROM testgame_SandraTriplets t 
       
    
 JOIN  testgame_SandraConcept c1 ON t.`idConceptStart`=  c1.`id`
 JOIN  testgame_SandraConcept c2 ON t.`idConceptLink`=  c2.`id`
 JOIN  testgame_SandraConcept c3 ON t.`idConceptTarget`=  c3.`id`
 LEFT JOIN  testgame_SandraConcept c4 ON t.`flag`=  c4.`id`";

    }


}