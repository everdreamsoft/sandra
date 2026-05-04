<?php
declare(strict_types=1);

namespace SandraCore;

class CommonFunctions
{
    public static function somethingToConceptId(mixed $something, System $system): mixed
    {

        $concept = self::somethingToConcept($something,$system);

        return $concept->idConcept ;

    }
    
    

    public static function somethingToConcept(mixed $something, System $system): Concept
    {

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

    public static function createEntity(mixed $subject, mixed $verb, mixed $target, mixed $referenceArray, EntityFactory $factory, System $system, bool $autocommit = false, int|bool $updateOnExistingVerb = 0): Entity
    {

        $subjectConceptId = self::somethingToConceptId($subject,$system);
        $verbConceptId = self::somethingToConceptId($verb,$system);
        $targetConceptId = self::somethingToConceptId($target,$system);

       // Start a single transaction for the whole entity creation.
       // Always commit to balance the begin() — TransactionManager handles nesting:
       // if a caller wraps in their own begin/commit (or wrap()), this commit just
       // decrements depth; only the outermost commit actually fires PDO::commit().
       $pdo = $system->getConnection();
       TransactionManager::begin($pdo);

       $entityId = DatabaseAdapter::rawCreateTriplet($subjectConceptId,$verbConceptId,$targetConceptId,$system,$updateOnExistingVerb,true);

       if (is_array($referenceArray)) {
           foreach ($referenceArray as $key => $value) {

               $conceptId = self::somethingToConceptId($key, $system);
               DatabaseAdapter::rawCreateReference($entityId, $conceptId, $value, $system, true);
           }
       }
        DatabaseAdapter::rawCreateReference($entityId, $system->systemConcept->get('creationTimestamp'), time(), $system, true);

       TransactionManager::commit();

        return new Entity($subject,$referenceArray,$factory,$entityId,$verb,$target,$system);


    }



}