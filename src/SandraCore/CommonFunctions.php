<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.03.2019
 * Time: 11:35
 */

namespace SandraCore;


class CommonFunctions
{

    public static function somethingToConceptId($something,System $system){

        $concept = self::somethingToConcept($something,$system);

        return $concept->idConcept ;

    }

    public static function somethingToConcept($something,System $system):Concept{

        $concept = null ;

        if (is_string($something)){
            $concept = $system->conceptFactory->getConceptFromShortnameOrId($something);
        }
        if ($something instanceof Concept){
            $concept = $something ;
        }
        if ($something instanceof Entity){
            $concept = $something->subjectConcept ;
        }
        if (is_numeric($something)){
            $concept  = $system->conceptFactory->getConceptFromId($something);
        }

        return $concept ;
    }

    public static function createEntity($subject,$verb,$target,$referenceArray,$factory,$system,$autocommit=false){

        $subjectConceptId = self::somethingToConceptId($subject,$system);
        $verbConceptId = self::somethingToConceptId($verb,$system);
        $verbConceptTarget = self::somethingToConceptId($verb,$system);

       $entityId = DatabaseAdapter::rawCreateTriplet($subjectConceptId,$verbConceptId,$verbConceptTarget,$system,0,false);

       foreach ($referenceArray as $key => $value){

           $conceptId = self::somethingToConceptId($key,$system);
           DatabaseAdapter::rawCreateReference($entityId,$conceptId,$value,$system,false);
       }
        DatabaseAdapter::rawCreateReference($entityId, $system->systemConcept->get('creationTimestamp'), time(), $system, false);

       if($autocommit){
           DatabaseAdapter::commit();

       }

        return new Entity($subject,$referenceArray,$factory,$entityId,$verb,$target,$system);


    }



}