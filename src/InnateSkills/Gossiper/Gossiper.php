<?php


namespace InnateSkills\Gossiper;


use SandraCore\CommonFunctions;
use SandraCore\Concept;
use SandraCore\DatabaseAdapter;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\ForeignConcept;
use SandraCore\ForeignEntity;
use SandraCore\Reference;
use SandraCore\System;

class Gossiper
{

    public $updateOnRef = null;
    public $indexRef = array();
    public $foreignEntityMap = array();
    public $localEntityMap = array();
    public $updateRefCount = 0;
    public $createRefCount = 0;
    public $equalRefCount = 0;
    public $bufferTripletsArray = array();
    public $shortnameDict = array();
    /**
     * @var System
     */
    private System $sandra;
    private $autocommit;

    public function __construct(System $sandra, $autocommit = true)
    {

        $this->sandra = $sandra;
        $this->updateOnRef = new ForeignConcept('null', $sandra);
        $this->autocommit = $autocommit;


    }

    public function receiveEntityFactory($json): EntityFactory
    {

        $jsonObject = json_decode($json);

        $updateOnRefShortName = $jsonObject->gossiper->updateOnReferenceShortname;
        $this->updateOnRef = $this->sandra->conceptFactory->getConceptFromShortnameOrId($updateOnRefShortName);

        if (isset($jsonObject->gossiper->shortNameDictionary)) {
            $this->shortnameDict = (array)$jsonObject->gossiper->shortNameDictionary;
        };

        //first we cycle trough joinedFactories and first register joined entities
        if (isset ($jsonObject->entityFactory->joinedFactory) && $jsonObject->entityFactory->joinedFactory) {
            foreach ($jsonObject->entityFactory->joinedFactory as $joinedFactory) {

                $gossiper = new Gossiper($this->sandra, false);
                $gossiper->receiveEntityFactory(json_encode($joinedFactory));
                $this->mergeChildGossip($gossiper);
            }

        }


        $entityFactoryJson = $jsonObject->entityFactory;
        $isa = $entityFactoryJson->is_a;
        $containedIn = $entityFactoryJson->contained_in_file;

        $entityFactory = new EntityFactory($isa, $containedIn, $this->sandra);

        if (isset($entityFactoryJson->entityArray)) {
            foreach ($entityFactoryJson->entityArray as $entityJson) {


                $foreign = new ForeignEntity(null, [], $entityFactory, $entityJson->id, $this->sandra);
                self::convertReferences($foreign, $entityJson->referenceArray);
            }

        }

        $entityFactory->populateFromSearchResults($this->indexRef, $this->updateOnRef);
        $entityFactory->getTriplets();

        if (isset($entityFactoryJson->entityArray)) {
            foreach ($entityFactoryJson->entityArray as $entityJson) {

                $entity = self::gossipReferences($entityFactory, $entityJson->referenceArray, $entityJson->subjectUnid);
                if (isset($entityJson->triplets)) {
                    $this->bufferTriplets($entity, $entityJson->triplets);
                }

            }

        }
        if ($this->autocommit) {
            $this->executeTripletBuffer();
            DatabaseAdapter::commit();
        }

        return $entityFactory;

    }

    private function mergeChildGossip(Gossiper $gossiper): self
    {

        $this->foreignEntityMap = $gossiper->foreignEntityMap + $this->foreignEntityMap;
        $this->bufferTripletsArray = $gossiper->bufferTripletsArray + $this->bufferTripletsArray;
        $this->indexRef = $gossiper->indexRef + $this->indexRef;
        $this->localEntityMap = $gossiper->localEntityMap + $this->localEntityMap;

        return $this;


    }

    private function convertReferences(Entity $entity, $refJsonObject)
    {

        $system = $this->sandra;
        $refArray = [];

        foreach ($refJsonObject as $ref) {

            $concept = new ForeignConcept($ref->concept->shortname, $system);
            $refArray[] = new Reference($concept, $entity, $ref->value, $system);
            /** @var $updateOnRef */
            if ($this->updateOnRef->getShortname() == $ref->concept->shortname) {
                $this->indexRef[] = $ref->value;
            }

        }


        return $refArray;


    }

    private function gossipReferences(EntityFactory $entityFactory, $refJsonObject, $foreignSubjectUnid): Entity
    {

        $discriminatingValue = $this->findDiscriminatoryReference($refJsonObject);
        $entityExist = null;

        if ($discriminatingValue)
            $entityExist = $entityFactory->getAllWith($this->updateOnRef->getShortname(), $discriminatingValue);

        if (!$entityExist) $entity = $entityFactory->createNew([], null, false);
        else $entity = reset($entityExist);


        foreach ($refJsonObject as $ref) {

            $create = false;
            $update = false;

            $shortname = $ref->concept->shortname;

            if (!$entity->get($shortname)) $create = true;
            else if ($entity->get($shortname) != $ref->value) $update = true;
            else $this->equalRefCount++;

            $keyValueArray[$ref->concept->shortname] = $ref->value;

            $entity->createOrUpdateRef($ref->concept->shortname, $ref->value, false);

            if ($update) $this->updateRefCount++;
            if ($create) $this->createRefCount++;

        }
        $this->foreignEntityMap[$foreignSubjectUnid] = $entity;
        $this->localEntityMap[$entity->subjectConcept->idConcept] = $entity;

        return $entity;


    }

    private function findDiscriminatoryReference($refJsonObject)
    {

        foreach ($refJsonObject as $ref) {

            $shortname = $ref->concept->shortname;

            if ($this->updateOnRef->getShortname() == $shortname)
                return $ref->value;
        }
        return null;
    }

    private function bufferTriplets(Entity $entity, $tripletJson): self
    {


        foreach ($tripletJson as $verb => $targets) {
            foreach ($targets as $target) {
                $this->bufferTripletsArray[$entity->subjectConcept->idConcept][$verb][$target] = $target;
            }
        }
        return $this;
    }

    private function executeTripletBuffer(): self
    {


        foreach ($this->bufferTripletsArray ?? [] as $subject => $verbs) {
            foreach ($verbs as $verb => $targetList) {
                foreach ($targetList as $target) {


                    //target is the unid of the foreign entity passed
                    //we need to find out our local entity
                    /** @var Entity $localEntity */
                    $localEntity = $this->localEntityMap[$subject];
                    // if is an entity for example DogEntity -> FriendWith -> anotherDogEntity
                    if (isset($this->foreignEntityMap[$target]->subjectConcept->idConcept)) {
                        $localTargetConcept = $this->foreignEntityMap[$target]->subjectConcept->idConcept;
                    } //its a pure shortname target for example LightBulbEntity -> hasStatus -> switchedOn
                    else if (isset($this->shortnameDict[$target])) {
                        $localTargetConcept = $this->shortnameDict[$target];
                        echo($localTargetConcept);
                    }


                    //triplet creating with entity
                    if (!$localEntity->hasVerbAndTarget($verb, $localTargetConcept)) {
                        $localEntity->subjectConcept->createTriplet(CommonFunctions::somethingToConcept($verb, $this->sandra),
                            CommonFunctions::somethingToConcept($localTargetConcept, $this->sandra), [], 0, false);
                    }

                }
            }
        }

        return $this;


    }

}