<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 16:15
 */

namespace SandraCore;


class ForeignEntity extends Entity
{

    private $entityIsa ;
    private $entityContainedIn ;
    private $factory ;
    public $entityId ; // The is the id of the table link
    public $entityRefs ; // The is the id of the table link
    public $system ;



    public function __construct($sandraConcept,$sandraReferencesArray,$factory,$entityId,System $system){

        $this->system = $system ;

        //print_r($sandraReferencesArray);

        foreach ($sandraReferencesArray as $sandraReferenceConceptId => $sandraReferenceValue){

            //is it a foreign reference or is the reference mapped ?
            if( $factory->isLocalMapedReference($sandraReferenceConceptId)){

                $localRelation =  $factory->isLocalMapedReference($sandraReferenceConceptId) ;

                $referenceConcept = $system->conceptFactory->getConceptFromShortnameOrId($localRelation);
                $sandraReferenceConceptId = $referenceConcept->idConcept ;
            }
            else {
                $referenceConcept = $system->conceptFactory->getForeignConceptFromId($sandraReferenceConceptId);
            }


            $ref  = new Reference($referenceConcept,$this,$sandraReferenceValue,$system);
            $this->entityRefs[$sandraReferenceConceptId] = $ref ;
            $this->entityId = $entityId ;
            //$this->factory = $factory ;

        }


    }

    public function save(EntityFactory $factory){


        foreach ($this->entityRefs as $key => $value){

            /* @var $value Reference */

            $refConceptShortname = $value->refConcept->idConcept ; // here we take the concept shortname as passed by the API

            $dataArray[$refConceptShortname]= $value->refValue ;



        }

        $factory->createNew($dataArray);


    }




}