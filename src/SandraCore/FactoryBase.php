<?php
namespace SandraCore;
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 09:29
 */



abstract class FactoryBase
{

    public $displayer ;
    public $tripletfilter ;
    public $system ;

    abstract public function getAllWith($referenceName, $referenceValue);
    abstract public function createNew($dataArray, $linkArray = null);

    public function __construct(System $system)
    {
        $this->system = $system ;

    }

    public function first($referenceName, $referenceValue) : ?Entity{

       $resultArray = $this->getAllWith($referenceName, $referenceValue) ;
       if (!is_array($resultArray)) return null ;

       $result = reset($resultArray);

        return $result;

    }

    public function last($referenceName, $referenceValue) : ?Entity{

        $resultArray = $this->getAllWith($referenceName, $referenceValue) ;
        if (!is_array($resultArray)) return null ;

        $result = end($resultArray);

        return $result;

    }
    

    public function getDisplay($format,$refToDisplay = null, $dictionnary=null){

        $displayer = $this->initDisplayer();
       
        $this->displayer = $displayer ;

        if (!is_null($dictionnary)) $displayer->setDisplayDictionary($dictionnary);
        if (!is_null($refToDisplay)) $displayer->setDisplayRefs($refToDisplay);

        return  $displayer->getAllDisplayable();
        

    }

    public function getOrCreateFromRef($refname,$refvalue):Entity{

       $entity = $this->first($refname,$refvalue);
       if(!$entity) {
        $entity =   $this->createNew(array($refname=>$refvalue));
               }

        return $entity ;
    }

    public function setFilter($verb=0,$target=0,$exclusion=false):EntityFactory{

        $verbConceptId = CommonFunctions::somethingToConceptId($verb,$this->system);
        $targetConceptId = CommonFunctions::somethingToConceptId($target,$this->system);

        $this->tripletfilter["$verbConceptId $targetConceptId"]['verbConceptId'] = $verbConceptId;
        $this->tripletfilter["$verbConceptId $targetConceptId"]['targetConceptId'] = $targetConceptId;
        $this->tripletfilter["$verbConceptId $targetConceptId"]['exclusion'] = $exclusion;

        return $this ;

    }


    protected function initDisplayer(){

        if (!isset($this->displayer)){

            $this->displayer = new displayer\Displayer($this);

        }

        return $this->displayer ;
        

    }

   


    


}