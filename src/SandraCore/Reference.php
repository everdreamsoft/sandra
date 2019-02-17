<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 13.01.2019
 * Time: 11:08
 */

namespace SandraCore;

class Reference implements Dumpable
{

    public $refConcept ;/** @var $refConcept Concept */
    public $refEntity ;  /** @var $refEntity SandraEntity */
    public $refValue ;
    private $system ;

    public function __construct(Concept $refConcept,Entity $refEntity,$refValue,System $system)
    {

        $this->refConcept = $refConcept;
        $this->refEntity = $refEntity;
        $this->refValue = $refValue;
        $this->system = $system ;
    }

    public function hasChangedFromDatabase(): bool
    {
        global  $tableReference, $dbLink;

        /** @var $refEntity SandraEntity */

        $inMemoryValue = $this->refValue ;

        $newValue = $this->reload();


        if ($inMemoryValue == $newValue){
            return false ; //means there is no change on that data

        }
        else{
            //means the data has changed
            return true ;

        }

    }

    public function reload()
    {
        global  $tableReference, $dbLink;

        /** @var $refEntity SandraEntity */


        $newValue = getReference($this->refConcept->idConcept,$this->refEntity->entityId);
        $this->refValue = $newValue ;

        return $newValue ;

    }

    public function save($newValue)
    {

        $newValue = createReference($this->refConcept->idConcept,$this->refEntity->entityId,$newValue);
        $this->refValue = $newValue ;

        return $newValue ;

    }
    
    public function dumpMeta()
    {
        
        $meta = $this->refValue ;
        
        return $meta ;
        
        
    }


}