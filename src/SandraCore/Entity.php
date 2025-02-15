<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 16:08
 */

namespace SandraCore;


use SandraCore\queryTraits\EntityQueryTrait;

class Entity implements Dumpable
{

    use EntityQueryTrait;


    private $entityIsa;
    private $entityContainedIn;
    /** @var $factory EntityFactory */
    public $factory;
    public $subjectConcept;
    /** @var $subjectConcept Concept */
    public $verbConcept;
    /** @var $verbConcept Concept */
    public $targetConcept;
    /** @var $targetConcept Concept */
    public $entityId; // The is the id of the table link
    public $entityRefs ; /** @var $entityRefs Reference[] */
    public $dataStorage ;
    public $isForeign = false ;

    public $system ;

    public function __construct($sandraConcept, $sandraReferencesArray, $factory, $entityId, $conceptVerb, $conceptTarget, System $system){

        $this->system = $system ;

        if(is_array($sandraReferencesArray)) {
            foreach ($sandraReferencesArray as $sandraReferenceConceptId => $sandraReferenceValue) {

                $ref = $sandraReferenceValue ;
                if(!($sandraReferenceValue instanceof Reference)) { //only if the reference is not already created

                    //if $sandraReferenceConceptId is not an id then we need to convert it
                    $sandraReferenceConcept = $system->conceptFactory->getConceptFromShortnameOrId($sandraReferenceConceptId);
                    $sandraReferenceConceptId = $sandraReferenceConcept->idConcept;

                    $referenceConcept = $this->system->conceptFactory->getForeignConceptFromId($sandraReferenceConceptId);

                    //todo replace zero for reference id
                    $ref = new Reference(0, $referenceConcept, $this, $sandraReferenceValue, $this->system);
                }
                $this->entityRefs[$sandraReferenceConceptId] = $ref;


            }
        }

        $this->entityId = $entityId;
        $this->factory = $factory;


        /** @var $sandraConcept Concept */

        $this->subjectConcept = $sandraConcept;
        $this->verbConcept = CommonFunctions::somethingToConcept($conceptVerb, $system);
        $this->targetConcept = CommonFunctions::somethingToConcept($conceptTarget, $system);


        /** @var $sandraConcept Concept */

        if (is_string($sandraConcept)) {

            return new ForeignEntity($sandraConcept, $sandraReferencesArray, $factory, $entityId, $system);
        }


        $sandraConcept->addEntity($this);


    }

    public function get($referenceName){

        $refId = $this->system->systemConcept->get($referenceName);
        //echoln("getting $referenceName is $refId");

        if (!isset($this->entityRefs[$refId]))
            return null ;

        return $this->entityRefs[$refId]->refValue ;

    }

    public function getJoined($joinVerb,$referenceName){



        $verbConceptId = CommonFunctions::somethingToConceptId($joinVerb,$this->system);
        //No joined data
        if (!isset($this->subjectConcept->tripletArray[$verbConceptId]))return null ;

        $joindedConceptId = reset($this->subjectConcept->tripletArray[$verbConceptId]);
        $joinedConcept = $this->system->conceptFactory->getConceptFromId($joindedConceptId);

        /** @var $factory EntityFactory */
        $factory = $this->factory ;

        //we find the joined factory
        $joinedFactory = $factory->joinedFactoryArray[$verbConceptId];

        /** @var $joinedFactory EntityFactory */
        //we need to find the correct datapath from the factoryK
        $mainVerb = CommonFunctions::somethingToConceptId($joinedFactory->entityReferenceContainer,$this->system) ;
        $mainTarget = CommonFunctions::somethingToConceptId($joinedFactory->entityContainedIn,$this->system) ;

        //no joined entity
        if (!isset($joinedConcept->entityArray[$mainVerb][$mainTarget])) return null;

        $joinedEntity = $joinedConcept->entityArray[$mainVerb][$mainTarget];
        return $joinedEntity->get($referenceName);

    }


    /**
     * @param $joinVerb
     * @return Entity[]|null
     */
    public function getJoinedEntities($joinVerb)
    {

        $return = null;
        $entities = array();

        $verbConceptId = CommonFunctions::somethingToConceptId($joinVerb, $this->system);
        //No joined data
        if (!isset($this->subjectConcept->tripletArray[$verbConceptId])) return null;

        $joindedConceptIds = $this->subjectConcept->tripletArray[$verbConceptId];


        /** @var $factory EntityFactory */

        $factory = $this->factory;

        if (!isset($factory->joinedFactoryArray[$verbConceptId])) return null;

        //we find the joined factory
        $joinedFactory = $factory->joinedFactoryArray[$verbConceptId];


        /** @var $joinedFactory EntityFactory */
        foreach ($joindedConceptIds ? $joindedConceptIds : array() as $conceptId) {

            if (isset($joinedFactory->entityArray[$conceptId])) {
                $entities[] = $joinedFactory->entityArray[$conceptId];
            }
        }

        return $entities;


    }


    public function getBrotherEntity($brotherVerb,$brotherTarget=null){

        if(!is_null($brotherTarget)) {

            $verbConceptId = CommonFunctions::somethingToConceptId($brotherVerb, $this->system);
            $targetConceptId = CommonFunctions::somethingToConceptId($brotherTarget, $this->system);

            $factory = $this->factory;
            //we find the brother entity
            if (!isset($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId][$targetConceptId])) return null;

            $entity = $factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId][$targetConceptId];
            return $entity ;
        }
        //target is null then we should have only one target
        $verbConceptId = CommonFunctions::somethingToConceptId($brotherVerb, $this->system);


        $factory = $this->factory;
        //we find the brother entity
        if (!isset($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId])) return null;
        // if(count($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId])>1)
        //   $this->system->systemError('400','entityFactory','critical',"multiple targets for verb". $brotherVerb) ;

        $entity = $factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId];


        return $entity ;

    }

    /**
     *
     * Return an a brother entity with the verb and target if existing
     *
     * @param $brotherVerb Entity|string|int
     * @param $brotherTarget Entity|string|int
     * @return Entity|null
     */
    public function getBrotherEntityOnVerbAndTarget($brotherVerb,$brotherTarget):?Entity{

            $verbConceptId = CommonFunctions::somethingToConceptId($brotherVerb, $this->system);
            $targetConceptId = CommonFunctions::somethingToConceptId($brotherTarget, $this->system);

            $this->factory->populateBrotherEntities($verbConceptId,$targetConceptId);

            $factory = $this->factory;
            //we find the brother entity
            if (!isset($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId][$targetConceptId])) return null;

            $entity = $factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId][$targetConceptId];
            return $entity ;


    }

    /**
     *
     * Return an array of brother entities having the verb passed in parameter
     *
     * @param $brotherVerb Entity|string|int
     * @return Entity[]
     */
    public function getBrotherEntitiesOnVerb($brotherVerb){

        //target is null then we should have only one target
        $verbConceptId = CommonFunctions::somethingToConceptId($brotherVerb, $this->system);

        $this->factory->populateBrotherEntities($verbConceptId);

        $factory = $this->factory;
        //we find the brother entity
        if (!isset($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId])) return array();

        $entities = $factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId];

        return $entities ;

    }


    public function getBrotherReference($brotherVerb,$brotherTarget=null,$referenceName=null){


        $entity = $this->getBrotherEntity($brotherVerb,$brotherTarget);

        if(is_null($entity)) {return null ;} else if(!is_array($entity)){

            return $entity->get($referenceName);
        }

        $result = null ;

        foreach ($entity as $entityTarget => $singleEntity){
            $result[$entityTarget] = $singleEntity->get($referenceName);

        }
        return $result ;

    }

    public function setBrotherEntity($brotherVerb,$brotherTarget,$referenceArray,$autocommit=true,$updateOnExistingVerb =false):Entity{

        $verbConceptId = CommonFunctions::somethingToConceptId($brotherVerb,$this->system);
        $targetConceptId = CommonFunctions::somethingToConceptId($brotherTarget,$this->system);

        /** @var $factory EntityFactory */
        $factory = $this->factory ;

        $brotherEntity = CommonFunctions::createEntity($this->subjectConcept,$brotherVerb,$brotherTarget,$referenceArray,$factory,$this->system,$autocommit,$updateOnExistingVerb);

        //we need to remove in the factory the replaced update
        if ($updateOnExistingVerb && isset($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId])) {

            array_pop($factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId]);
        }

        $factory->brotherEntitiesArray[$this->subjectConcept->idConcept][$verbConceptId][$targetConceptId] = $brotherEntity ;

        return $brotherEntity ;

    }


    /**
     *
     * Create a joined entity which is in fact a brother entity but force an entity as the target
     *
     * @param $brotherVerb
     * @param Entity $targetEntity
     * @param array $referenceArray
     * @param bool $autocommit
     * @param false $updateOnExistingVerb
     * @return Entity
     */
    public function setJoinedEntity($brotherVerb, Entity $targetEntity, array $referenceArray, $autocommit=true, $updateOnExistingVerb =false):Entity{

        return $this->setBrotherEntity($brotherVerb,  $targetEntity, $referenceArray,$autocommit,$updateOnExistingVerb);


    }


    public function getReference($referenceName): ?Reference
    {

        $refId = $this->system->systemConcept->get($referenceName);

        if (isset($this->entityRefs[$refId])) {
            return $this->entityRefs[$refId];
        }
        return null ;

    }

    public function createOrUpdateRef($referenceShortname, $value, $autocommit = true): Reference
    {

        //get old ref
        $old = $this->get($referenceShortname);

        $referenceConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($referenceShortname);
        $refId = DatabaseAdapter::rawCreateReference($this->entityId, $referenceConcept->idConcept, $value, $this->system, $autocommit);
        $ref = new Reference($refId, $referenceConcept, $this, $value, $this->system);
        $this->entityRefs[$referenceConcept->idConcept] = $ref;

        $factory = $this->factory;
        if (isset($factory->refMap[$referenceConcept->idConcept][$old])) {
            $currentRefmap = $factory->refMap[$referenceConcept->idConcept][$old];

            /** @var EntityFactory $factory */
            foreach ($currentRefmap ? $currentRefmap : array() as $index => $entity) {

                if ($entity->entityId == $this->entityId) {
                    unset($factory->refMap[$referenceConcept->idConcept][$old][$index]);
                    //if none existing anymore remove this reference
                    if (empty($factory->refMap[$referenceConcept->idConcept][$old])) {
                        unset($factory->refMap[$referenceConcept->idConcept][$old]);
                    }
                }
            }
        }

        $factory->refMap[$referenceConcept->idConcept][$value][] = $this;


        return $ref ;


    }

    public function getOrInitReference($referenceShortname,$value): Reference{

        $reference = $this->getReference($referenceShortname);

        if(is_null($reference)){

            $reference = $this->createOrUpdateRef($referenceShortname,$value);
        }

        return $reference ;

    }

    public function getStorage(){

        $this->dataStorage =  DatabaseAdapter::getStorage($this);

        return $this->dataStorage ;

    }

    public function setStorage($value, $autocommit = true)
    {

        DatabaseAdapter::setStorage($this, $value, $autocommit);
        $this->dataStorage = $value ;

    }

    public function dumpMeta(){

        $entity['id']=$this->entityId;

        $meta['entity'] = $entity;

        foreach ($this->entityRefs as $key => $value){
            /** @var $value Reference */

            $references[$value->refConcept->dumpMeta()] = $value->dumpMeta() ;
        }

        if (!$this instanceof ForeignEntity) {
            $conceptLinks = $this->subjectConcept->dumpMeta(true);
            $meta['conceptSubject']  = $conceptLinks ;

        }

        $meta['references'] = $references ;

        return $meta ;

    }


    /**
     * @param bool $hard
     */
    public function delete($hard = false,$autocommit=true)
    {


        if (!$hard) {

            $deletedConcept = CommonFunctions::somethingToConcept($this->system->deletedUNID, $this->system);

            $this->flag($deletedConcept,$autocommit);

        }


    }


    public function flag(Concept $flagConcept, $autocommit = true)
    {


        DatabaseAdapter::rawFlag($this, $flagConcept, $this->system, $autocommit);


    }

    public function isForeign()
    {

        if (!is_numeric($this->entityId)) return true;
        else return false;

        // return $this->isForeign ;


    }

    public function getDisplayRef()
    {

        $references = array();


        foreach ($this->entityRefs as $key => $value) {
            /** @var $value Reference */

            $references[$value->refConcept->dumpMeta()] = $value->dumpMeta();
        }

        return $references;


    }


    public function destroy(){

        $this->system = null ;
        $this->factory = null ;


        $this->subjectConcept = null ;
        $this->verbConcept = null ;
        $this->targetConcept = null ;

        foreach ($this->entityRefs as $ref) {

            $ref->destroy();
            $ref = null;

        }


    }

    protected function _getEntity(): Entity
    {
        return $this;
    }
}
