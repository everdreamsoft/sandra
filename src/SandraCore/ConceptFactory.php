<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 16:32
 */

namespace SandraCore;



class ConceptFactory
{
    private  $conceptMapFromId = array();
    public $system ;

    public function __construct(System $system)
    {

        $this->system = $system ;

    }

    public  function getConceptFromId($conceptId,$childObject=null)
    {

        if ( isset($this->conceptMapFromId[$conceptId])) {

            $concept = $this->conceptMapFromId[$conceptId];
        }  else {

            if($childObject) {

                $concept = new $childObject($conceptId);
            }
            else {

                $concept = new Concept($conceptId,$this->system);
                //$concept->getDisplayName();
            }

            $this->conceptMapFromId[$conceptId] = $concept;

        }

        return $concept;

    }

    public  function getConceptFromShortnameOrId($conceptWeDontKnow)
    {

        //echoln("getting SC ".getSC('sandraAllowAccess'));


        //we received a shortname
        if (!($conceptWeDontKnow instanceof Concept)) {

            //do we get a concept id ?
            if(!is_numeric($conceptWeDontKnow))
            {

                $conceptId = $this->system->systemConcept->get($conceptWeDontKnow) ;

                if(!$conceptId){
                    die("invalid concept $conceptWeDontKnow");
                }
                

            }
            else{

                $conceptId = $conceptWeDontKnow ;

            }

        }

        else{
            return $conceptWeDontKnow ;
        }



        return $this->getConceptFromId($conceptId);
    }

    public  function getConceptFromShortnameOrIdOrCreateShortname($conceptWeDontKnow)
    {

        //echoln("getting SC ".getSC('sandraAllowAccess'));


        //we received a shortname
        if (!($conceptWeDontKnow instanceof Concept)) {

            //do we get a concept id ?
            if(!is_numeric($conceptWeDontKnow))
            {

                $conceptId = $this->system->systemConcept->get($conceptWeDontKnow) ;



            }
            else{

                $conceptId = $conceptWeDontKnow ;

            }

        }

        else{
            return $conceptWeDontKnow ;
        }



        return $this->getConceptFromId($conceptId);
    }

    public  function getForeignConceptFromId($conceptId)
    {
        
        if ( isset($this->conceptMapFromId[$conceptId])) {

            $concept =  $this->conceptMapFromId[$conceptId];
        } else {


            $concept = new ForeignConcept($conceptId,$this->system);
            $concept->displayName = $conceptId ;

            $this->conceptMapFromId[$conceptId] = $concept;

        }

        return $concept;
    }

    public function destroy(){


        $this->system = null ;
        foreach ($this->conceptMapFromId as $concept){

            $concept->destroy();
            $concept = null ;

        }

    }

    


}