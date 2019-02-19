<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 15.02.19
 * Time: 11:15
 */

namespace InnateSkills\LearnFromWeb;

use SandraCore\Entity;
use SandraCore\FactoryManager;
use SandraCore\Reference;
use SandraCore\System;
use SandraCore\ForeignEntityAdapter ;


class LearnFromWeb
{
    public $system;
    public $webLearnerFactory;

    public function __construct(System $system)
    {

        $this->system = $system;
        $this->webLearnerFactory = new WebLearnerFactory($this->system);
        $system->systemConcept->get('learnerName');
        $this->webLearnerFactory->populateLocal();
        $this->webLearnerFactory->populateBotherEntiies('has','vocabulary');




    }

    public function createOrUpdate($learnerName, $vocabularyArray, $url, $path, $learnerLocalIsa, $learnerLocalFile,$fuseLocalConcept,$fuseRemoteConcept)
    {

        //print_r( $this->webLearnerFactory->dumpMeta() );

       $existEntity = $this->webLearnerFactory->getAllWith('learnerName', $learnerName);
      // print_r( $this->webLearnerFactory) ;

       //print_r( $this->webLearnerFactory->dumpMeta() );

        if (is_array($existEntity)){


            foreach ($existEntity as $entity){

                

            }

        }


        if (!isset($existEntity)) {


            $this->create($learnerName, $vocabularyArray, $url, $path, $learnerLocalIsa, $learnerLocalFile,$fuseLocalConcept,$fuseRemoteConcept);


        } else {


            return reset($existEntity);
        }

    }


    public function create($learnerName, $vocabularyArray, $url, $path, $learnerLocalIsa, $learnerLocalFile,$fuseLocalConcept,$fuseRemoteConcept)
    {

        $vocabulary = array();

        //Parse the vocabulary
        foreach ($vocabularyArray as $remoteName => $localName) {

            $referencesToAdd[$localName] = $remoteName;
            $this->system->systemConcept->get($localName); //make sure to local reference is created

        }

        $entityData['learnerName'] = $learnerName;
        $entityData['url'] = $url;
        $entityData['path'] = $path;
        $entityData['isa_a'] = $learnerLocalIsa;
        $entityData['contained_in_file'] = $learnerLocalFile;
        $entityData['fuseLocalRef'] = $fuseLocalConcept;
        $entityData['fuseRemoteRef'] = $fuseRemoteConcept;


        if (isset($referencesToAdd)) {
            $vocabulary['has']['vocabulary'] = $referencesToAdd;
        }

        $learnerEntity = $this->webLearnerFactory->createNew($entityData, $vocabulary);
        return $learnerEntity;


    }

    public function learn(Entity $learnerConcept)
    {

        //print_r($learnerConcept);
        //print_r($learnerConcept->dumpMeta());
        //$learnerConcept->subjectConcept->system = null ;
        $refVocabulary = array();


        $hasConcept = $this->system->conceptFactory->getConceptFromShortnameOrId('has');
        $vocabularyConcept = $this->system->conceptFactory->getConceptFromShortnameOrId('vocabulary');

        $vocabularyEntity = $learnerConcept->subjectConcept->getEntity($hasConcept,$vocabularyConcept);


        //building the vocabulary

        foreach  ((array) $vocabularyEntity->entityRefs as $reference){

            /* @var Reference $reference */

            $vocabulary[$reference->refValue] = $reference->refConcept->getShortname() ;


        }
        
        $learnerName = $learnerConcept->get('learnerName');
        $url = $learnerConcept->get('url');
        $path = $learnerConcept->get('path');
        $isa_a = $learnerConcept->get('isa_a');
        $fuseLocalRef = $learnerConcept->get('fuseLocalRef');
        $fuseRemoteRef = $learnerConcept->get('fuseRemoteRef');
        $contained_in_file = $learnerConcept->get('contained_in_file');


        $factory = $this->system->factoryManager->create("$learnerName"."_factory", $isa_a, $contained_in_file);
        $foreignAdapter = new ForeignEntityAdapter($url, $path, $this->system);




        $foreignAdapter->adaptToLocalVocabulary($vocabulary);


        $factory->foreignPopulate($foreignAdapter);


        $factory->setFuseForeignOnRef($fuseRemoteRef, $fuseLocalRef);
        $factory->fuseRemoteEntity();



        $factory->saveEntitiesNotInLocal();

        print_r($factory->dumpMeta());

        return($factory->return2dArray());


    }

    public function getFactoryFromLearnerName($learnerName)
    {


        $learnerEntities = $this->webLearnerFactory->getAllWith('learnerName', $learnerName);
        $learnerEntity = end($learnerEntities); //make sure we take the last.

        $learnerName = $learnerEntity->get('learnerName');
        $isa_a = $learnerEntity->get('isa_a');
        $contained_in_file = $learnerEntity->get('contained_in_file');


        $factory = $this->system->factoryManager->create("$learnerName"."_factory", $isa_a, $contained_in_file);

        return $factory ;








    }

    public function test($firstEntity)
    {

    //$firstEntity = reset($this->webLearnerFactory->entityArray);
    if($firstEntity instanceof Entity) {

        return $this->learn($firstEntity);
    }
    else return false ;


    }

}