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

/**
 * Class Displayer
 * @package SandraCore\displayer
 */
class Displayer
{

    private $factoryArray;
    private $mainFactory;
    private $displayReferenceArray;
    private $displayDictionary ;

    public function __construct(EntityFactory $factory)
    {

        $this->factoryArray[] = $factory;
        $this->mainFactory = $factory ;

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


    $this->displayDictionary =  ConceptDictionary::buildForeign($dictionary,$this->mainFactory->system);


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


        //Cycle trough all factories
        foreach($this->factoryArray as  $factory) {


            foreach ($factory->entityArray as $key => $entity) {


                foreach ($this->displayReferenceArray as $referenceObject) {


                    $refConceptUnid = $referenceObject->idConcept;

                    //we have a concept name matching the dictionary
                    if (isset ($this->displayDictionary->entityRefs[$referenceObject->getShortname()])){

                        $refConceptName = $this->displayDictionary->entityRefs[$referenceObject->getShortname()]->refValue;

                    }
                    else {
                        $refConceptName = $referenceObject->getDisplayName('system');
                    }

                    if(!isset( $entity->entityRefs[$refConceptUnid])) continue ;

                    if(!is_object( $entity->entityRefs[$refConceptUnid])) continue ;

                    $returnArray[$key][$refConceptName] = $entity->entityRefs[$refConceptUnid]->refValue;

                }

            }
        }

        return $returnArray ;


    }

}