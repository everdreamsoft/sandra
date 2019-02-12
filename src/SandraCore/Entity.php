<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 16:08
 */

namespace SandraCore;


class Entity
{


    private $entityIsa ;
    private $entityContainedIn ;
    private $factory ;
    public $subjectConcept ;  /** @var $subjectConcept Concept */
    public $verbConcept ; /** @var $verbConcept Concept */
    public $targetConcept ; /** @var $targetConcept Concept */
    public $entityId ; // The is the id of the table link
    public $entityRefs ; // The is the id of the table link

    public $system ;

    public function __construct($sandraConcept,$sandraReferencesArray,$factory,$entityId,$conceptVerb,$conceptTarget,System $system){

        foreach ($sandraReferencesArray as $sandraReferenceConceptId => $sandraReferenceValue){


            $referenceConcept = ConceptFactory::getConceptFromId($sandraReferenceConceptId);

            $ref  = new Reference($referenceConcept,$this,$sandraReferenceValue);
            $this->entityRefs[$sandraReferenceConceptId] = $ref ;
            $this->entityId = $entityId ;
            //$this->factory = $factory ;

        }

        $this->subjectConcept = $sandraConcept;
        $this->verbConcept = $conceptVerb;
        $this->targetConcept = $conceptTarget;

        /** @var $sandraConcept Concept */

        $sandraConcept->addEntity($this);


    }

    public function get($referenceName){

        $refId = getSC($referenceName);
        //echoln("getting $referenceName is $refId");

        return $this->entityRefs[$refId]->refValue ;


    }

    public function getReference($referenceName): Reference{

        $refId = getSC($referenceName);
        //echoln("getting $referenceName is $refId");

        return $this->entityRefs[$refId] ;

    }

    public function createOrUpdateRef(Concept $referenceConcept,$value): Reference{

        createReference($referenceConcept->idConcept,$this->entityId,$value);
        $ref  = new Reference($referenceConcept,$this,$value);
        $this->entityRefs[$referenceConcept->idConcept] = $ref ;

        return $ref ;

        //Todo rebuild factory index


    }




}