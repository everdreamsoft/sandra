<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:27
 */

namespace SandraCore;




class Concept
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
    public $foreignPrefix = 'f:';


    public function __construct($value)
    {
        $this->setConceptId($value);
    }

    public function getShortname()
    {

        if (is_null($this->conceptShortName)) {
            $this->conceptShortName = getSCS($this->idConcept);
        }
        return $this->conceptShortName;


    }

    public function addEntity(SandraEntity $entity)
    {

        //print_r($entity);
        // echoln("XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX");
        $verb = $entity->verbConcept ;
        $this->entityArray[$verb->idConcept] = $entity ;


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











}