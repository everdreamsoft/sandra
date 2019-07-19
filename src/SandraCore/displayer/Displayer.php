<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 09:32
 */

namespace SandraCore\displayer;


use SandraCore\Concept;
use SandraCore\EntityFactory;
use SandraCore\humanLanguage\ConceptDictionary;
use SandraCore\Reference;

/**
 * Class Displayer
 * @package SandraCore\displayer
 */
class Displayer
{

    public $factoryArray;
    public $mainFactory;
    public $displayReferenceArray;
    public $displayDictionary ; //this is for foreign entity to map into local concepts
    public $displayKeyMap = array();
    public  $displayType ;

    public function __construct(EntityFactory $factory, DisplayType $displayType = null)
    {

        $this->factoryArray[] = $factory;
        $this->mainFactory = $factory ;

        if ($displayType == null) {

            $displayType = new DefaultDisplay();
        }

        $this->displayType = $displayType ;

    }

    public function setDisplayRefs($refs)

    {

        foreach ($refs as $key => $value) {


            //we received a shortname
            if (!is_object($value)) {


                //do we get a concept id ?
                if (!is_numeric($value)) {
                    $conceptId = $this->mainFactory->system->systemConcept->tryGetSC($value);
                } else {
                    $conceptId = $value;

                }


                if ($conceptId) {
                    $refConcept = $this->mainFactory->system->conceptFactory->getForeignConceptFromId($conceptId);
                    $this->displayReferenceArray[] = $refConcept;
                }

            } else if ($value instanceof Concept) {

                $this->displayReferenceArray[] = $value;

            } else {

                $this->mainFactory->system->systemError(300,$this,300,"Invalid Ref in Displayable".print_r($value));

            }
        }

    }

    /**
     * A display dictionnary will define how to name the concepts that should be displayed
     *
     * @param array $dictionary
     *
     */
    public function setDisplayDictionary($dictionary)

    {


        //$this->displayDictionary =  ConceptDictionary::buildForeign($dictionary,$this->mainFactory->system);
        //I don't know what this method for commenting it for the moment

        $this->displayDictionary = ConceptDictionary::stringToStringDict($dictionary);


    }

    public function addFactory($factory)

    {


        $this->factoryArray[] = $factory;


    }



    public function getAllDisplayable()
    {
        $returnArray = array();



        //by default we display all references
        if (!is_array($this->displayReferenceArray)){

            $this->displayReferenceArray = $this->mainFactory->sandraReferenceMap ;

        }


        $return = $this->displayType->getDisplay($this);

        return $return ;


    }

    public function getDisplayKey(Concept $referenceObject){


        if (isset($this->displayKeyMap[$referenceObject->idConcept])){

            return $this->displayKeyMap[$referenceObject->idConcept] ;

        }

        $shortname = $referenceObject->getShortname() ;

        $this->displayKeyMap[$referenceObject->idConcept] = $shortname ;

        if (isset ($this->displayDictionary[$shortname])) {
            $this->displayKeyMap[$referenceObject->idConcept] = $this->displayDictionary[$shortname];
        }

        return $this->displayKeyMap[$referenceObject->idConcept] ;



    }

}