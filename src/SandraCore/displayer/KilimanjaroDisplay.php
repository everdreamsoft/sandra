<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 2020-07-19
 * Time: 11:35
 */

namespace SandraCore\displayer;



 class KilimanjaroDisplay extends DisplayType
{
    public $withMeta = true ;

    public function __construct($withMeta=true)
    {
        $this->withMeta = $withMeta ;

    }


     function getDisplay(): array
    {

        $displayer = $this->displayer ;
        $dataArray = [];
        $returnArray = array();


        //Cycle trough all factories
        foreach($displayer->factoryArray as  $factory) {


            foreach ($factory->entityArray as $key => $entity) {
                $returnArray = [];

                foreach ($displayer->displayReferenceArray as $referenceObject) {


                    $refConceptUnid = $referenceObject->idConcept;

                    //we have a concept name matching the dictionary
                    $refConceptName = $displayer->getDisplayKey($referenceObject);

                    if(!isset( $entity->entityRefs[$refConceptUnid])) continue ;

                    if(!is_object( $entity->entityRefs[$refConceptUnid])) continue ;

                    $returnArray[$refConceptName] = $entity->entityRefs[$refConceptUnid]->refValue;
                    $dataArray[] = $returnArray ;

                }

            }
        }
        if($this->withMeta) {
            if (is_array($this->withMeta)) $finalArray['meta'] = $this->withMeta;
            $finalArray['meta']['view'] = 'kilimanjaro';

        }


        $finalArray['data'] = $dataArray;

        return $finalArray ;


    }


 }