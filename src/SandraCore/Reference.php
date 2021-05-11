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
    private $system;
    public $refId;

    public function __construct($id, Concept $refConcept, Entity &$refEntity, $refValue, System $system)
    {

        $this->refConcept = $refConcept;
        $this->refId = $id;
        $this->refEntity = $refEntity;
        $this->refValue = $refValue;
        $this->system = $system;
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

        DatabaseAdapter::rawCreateReference($this->refEntity->entityId,
            $this->refConcept->idConcept,
            $newValue,
        $this->system);
        $this->refValue = $newValue ;

        return $newValue ;

    }
    
    public function dumpMeta()
    {
        
        $meta = $this->refValue ;
        
        return $meta ;
        
        
    }

    public function destroy()
    {

       $this->system = null ;

        $this->refConcept = null;
        $this->refEntity = null;
        $this->refValue = null;
        $this->system = null;
        $this->refId = null;


    }


}