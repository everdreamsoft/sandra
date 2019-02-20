<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 09:29
 */

namespace SandraCore;


class FactoryBase
{

    public $displayer ;
    

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