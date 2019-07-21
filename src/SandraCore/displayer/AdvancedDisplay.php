<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 2019-07-19
 * Time: 11:35
 */

namespace SandraCore\displayer;


 class AdvancedDisplay extends DisplayType
{


    function getDisplay(Displayer $displayer): array
    {


        //Cycle trough all factories
        foreach($displayer->factoryArray as  $factory) {


            foreach ($factory->entityArray as $key => $entity) {


                foreach ($displayer->displayReferenceArray as $referenceObject) {


                    $refConceptUnid = $referenceObject->idConcept;

                    //we have a concept name matching the dictionary
                    $refConceptName = $displayer->getDisplayKey($referenceObject);

                    if(!isset( $entity->entityRefs[$refConceptUnid])) continue ;

                    if(!is_object( $entity->entityRefs[$refConceptUnid])) continue ;

                    $returnArray[$key][$refConceptName]['value'] = $entity->entityRefs[$refConceptUnid]->refValue;

                }

            }
        }



        return $returnArray ;


    }
}