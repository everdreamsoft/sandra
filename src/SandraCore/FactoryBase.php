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
    abstract public function populateLocal($limit = 10000,$offset = 0,$asc='ASC');

    public $populated ;

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


    public function createViewTable($name):EntityFactory{


        if (!$this->populated){
            $this->populateLocal(1000,0,'DESC');

        }

        foreach ($this->sandraReferenceMap as $entity){

            //'LEFT JOIN `$tableReference` $refSQLCounter ON l.id = $refSQLCounter.`linkReferenced`"';


                    $sql = " CREATE OR REPLACE VIEW view_$name AS SELECT l.idConceptStart unid, $SQLViewField, 
                    FROM_UNIXTIME(rf.value) updated FROM `$tableReference` rf JOIN  $tableLink l ON l.id = rf.`linkReferenced` 
                    $SQLviewFilterJoin WHERE $SQLviewFilterCondition AND l.idConceptLink = $tlink AND l.idConceptTarget = $tg";


        }






    }

   


    


}