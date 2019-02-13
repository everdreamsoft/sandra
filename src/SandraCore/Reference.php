<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 13.01.2019
 * Time: 11:08
 */

namespace SandraCore;

class Reference
{

    public $refConcept ;
    public $refEntity ;  /** @var $refEntity SandraEntity */
    public $refValue ;

    public function __construct($refConcept,$refEntity,$refValue)
    {

        $this->refConcept = $refConcept;
        $this->refEntity = $refEntity;
        $this->refValue = $refValue;
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


}