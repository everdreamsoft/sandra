<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:27
 */

namespace SandraCore;




class Concept implements Dumpable
{

    public $idConcept;
    public $tripletArray;
    public $reverseTriplet;
    public $tripletObjectArray;
    public $referenceArray; //deprecated should become consolidated referenceArray
    public $masterReferenceArray;
    public $masterLinkId;
    public $conceptShortName;
    public $displayName;
    public $entities;
    public $system;
    public $entityArray;
    public $foreignPrefix = 'f:';


    public function __construct($value, System $system)
    {

       $this->system = $system ;
        $this->setConceptId($value);
    }

    public function getShortname()
    {

        if (is_null($this->conceptShortName)) {
            $this->conceptShortName = $this->system->systemConcept->getSCS($this->idConcept);
        }
        return $this->conceptShortName;


    }

    public function addEntity(Entity $entity)
    {


        $verb = $entity->verbConcept ;
        $target = $entity->targetConcept ;
        return $this->entityArray[$verb->idConcept][$target->idConcept] = $entity ;


    }

    public function getEntity($verbConcept,$targetConcept)
    {

        if (isset ($this->entityArray[$verbConcept->idConcept][$targetConcept->idConcept])){

            return $this->entityArray[$verbConcept->idConcept][$targetConcept->idConcept];
        }

        else return null ;


    }

    public function setConceptId($value)
    {

        if (is_nan($value))
            conceptNaNExeption();
        $this->idConcept = $value;

    }


    public function getDisplayName($language=null)
    {
        if (!$this->displayName){


            //we look if there is a shortname if not we get it
            if ($this->conceptShortName or $this->getShortname()) {
                $this->displayName = $this->conceptShortName;




            }

            else {
                $this->displayName = $this->idConcept;

            }
        }

        $displayName = $this->displayName;

        if ($this instanceof ForeignConcept) $displayName = 'f:' . $this->displayName;


        return $displayName ;

    }

    public function setConceptTriplets($su = 0)
    {

        global $tableLink, $tableReference;
        //look at the followup object


        $this->tripletArray = getLinks($this->idConcept, 0, 0, 0, 1);



    }


    public function createTriplet(Concept $verb, Concept $target, array $sandraRefArray = null)
    {

        //Todo add the reference
        $link = createLink($this->idConcept,$verb->idConcept,$target->idConcept);

    }

    public function getConceptTriplets($su = 0)
    {

        //If triplet empty gofind
        if (empty($this->tripletArray))
            $this->setConceptTriplets($su);

        return $this->tripletArray;


    }
    
    public function dumpMeta($withTriplet = false)
    {



        if ($withTriplet) {
            $response['concept'] = $this->getDisplayName();
            if($this->tripletArray) {
                foreach ($this->tripletArray as $link => $value) {
                    $verbDisplayName = $this->system->conceptFactory->getForeignConceptFromId($link);
                    $response["verb $link ". $verbDisplayName->getDisplayName()][] = $value;


                }
            }
        return $response ;
        }

        $return = $this->getDisplayName() ;

        return $return ;
        
    }


}