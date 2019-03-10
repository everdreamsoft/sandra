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



}