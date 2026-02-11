<?php
declare(strict_types=1);

namespace SandraCore;

use SandraCore\Exception\ConceptNotFoundException;

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
            }

            $this->conceptMapFromId[$conceptId] = $concept;

        }

        return $concept;

    }

    public  function getConceptFromShortnameOrId($conceptWeDontKnow)
    {

        //we received a shortname
        if (!($conceptWeDontKnow instanceof Concept)) {

            //do we get a concept id ?
            if(!is_numeric($conceptWeDontKnow))
            {

                $conceptId = $this->system->systemConcept->get($conceptWeDontKnow) ;

                if(!$conceptId){
                    throw new ConceptNotFoundException("invalid concept $conceptWeDontKnow");
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