<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 09:29
 */

namespace SandraCore;


abstract class FactoryBase
{

    public $displayer ;

    abstract public function getAllWith($referenceName, $referenceValue);

    public function first($referenceName, $referenceValue){

       $resultArray = $this->getAllWith($referenceName, $referenceValue) ;
       if (!is_array($resultArray)) return null ;

       $result = reset($resultArray);

        return $result;

    }

    public function last($referenceName, $referenceValue){

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


    protected function initDisplayer(){

        if (!isset($this->displayer)){

            $this->displayer = new displayer\Displayer($this);

        }

        return $this->displayer ;
        

    }

   


    


}