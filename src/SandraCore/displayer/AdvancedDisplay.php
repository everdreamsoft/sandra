<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 2019-07-19
 * Time: 11:35
 */

namespace SandraCore\displayer;


 use SandraCore\CommonFunctions;
 use SandraCore\System;

 class AdvancedDisplay extends DisplayType
{
    private $unidAsKey = false ;
    private $displayPropertiesRaw = array(); // This is the array before we know the datagraph (sandra instance)
    private $displayProperties = array(); // This is the array with concept ids converted
    private $showUnidBool = false; // This is the array with concept ids converted



    function getDisplay(): array
    {
        $displayer = $this->displayer ;

        $this->buildDisplayArrayFromDatagraph();


        //Cycle trough all factories
        foreach($displayer->factoryArray as  $factory) {


            foreach ($factory->entityArray as $key => $entity) {

                $entityValue = null ;

                //meta
                if ($this->showUnidBool){
                    $entityValue['unid'] = $key ;

                }



                foreach ($displayer->displayReferenceArray as $referenceObject) {

                    $arrayKey = null ;

                    $refConceptUnid = $referenceObject->idConcept;

                    //we have a concept name matching the dictionary
                    $refConceptName = $displayer->getDisplayKey($referenceObject);

                    if(!isset( $entity->entityRefs[$refConceptUnid])) continue ;

                    if(!is_object( $entity->entityRefs[$refConceptUnid])) continue ;

                    //$returnArray[$arrayKey][$refConceptName]['value'] = $entity->entityRefs[$refConceptUnid]->refValue;

                    //$entityValue['properties'][$refConceptName]['value'] =  $entity->entityRefs[$refConceptUnid]->refValue;
                    //$entityValue['properties'][$refConceptName]['value'] =  $entity->entityRefs[$refConceptUnid]->refValue;
                    //$entityValue['properties'][$refConceptName]['dataType'] =  $this->getDisplayProperty($refConceptUnid);

                    $properties['name']=$refConceptName ;
                    $properties['value']=$entity->entityRefs[$refConceptUnid]->refValue;
                    $properties['dataType']=$this->getDisplayProperty($refConceptUnid);

                    $entityValue['properties'][] = $properties ;





                }
                if ($this->unidAsKey)
                    $returnArray[$arrayKey] = $entityValue ;
                else
                    $returnArray[] = $entityValue ;

            }
        }

        return $returnArray ;

    }



    function conceptDisplayProperty($datagraphUnit,$typeToDisplay){

        /** @var Displayer $displayer */
        $displayer =  $this->displayer ;


      // $conceptId =  CommonFunctions::somethingToConceptId($datagraphUnit,$displayer->mainFactory->system);


         $this->displayPropertiesRaw[$datagraphUnit] = $typeToDisplay;




    }

     function getDisplayProperty($datagraphUnit){

         /** @var Displayer $displayer */
         $displayer =  $this->displayer ;


         $conceptId = CommonFunctions::somethingToConceptId($datagraphUnit,$displayer->mainFactory->system) ;

         if(isset ($this->displayProperties[$conceptId])) {
         return  $this->displayProperties[$conceptId] ;
         }
         return 'default';

     }

     function buildDisplayArrayFromDatagraph(){

        //We already have a datagraph formated array
         if (count($this->displayPropertiesRaw) == count($this->displayProperties))
             return ;

         /** @var Displayer $displayer */
         $displayer =  $this->displayer ;

         if (!isset($this->displayer)) return ;

         foreach ($this->displayPropertiesRaw as $key => $value){

             $conceptId = CommonFunctions::somethingToConceptId($key,$displayer->mainFactory->system) ;

             $this->displayProperties[$conceptId] = $value ;

         }

     }

     function setShowUnid($option = true){

        $this->showUnidBool = $option ;

     }

}