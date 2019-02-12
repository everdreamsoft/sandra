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
    private  $conceptMapFromId;
    private $system ;

    public function __construct($system)
    {

        $this->system = $system ;

    }

    public  function getConceptFromId($conceptId,$childObject=null)
    {

        if ($this->conceptMapFromId[$conceptId]) {

            $concept = $this->conceptMapFromId[$conceptId];
        }  else {

            if($childObject) {

                $concept = new $childObject($conceptId);
            }
            else {

                $concept = new Concept($conceptId);
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

                $conceptId = tryGetSC($conceptWeDontKnow) ;

                if(!$conceptId){
                    throw new Exception("invalid concept $conceptWeDontKnow");
                }




            }
            else{

                $conceptId = $conceptWeDontKnow ;

            }

        }

        else{
            return $conceptWeDontKnow ;
        }



        return self::getConceptFromId($conceptId);
    }

    public  function getForeignConceptFromId($conceptId)
    {



        if ( $this->conceptMapFromId[$conceptId]) {

            $concept =  $this->conceptMapFromId[$conceptId];
        } else {


            $concept = new ForeignConcept($conceptId);
            $concept->displayName = $conceptId ;

            $this->conceptMapFromId[$conceptId] = $concept;

        }

        return $concept;
    }


}