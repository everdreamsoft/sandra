<?php
namespace SandraCore;
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:49
 */


use SandraCore\displayer\Displayer;

class EntityFactory extends FactoryBase implements Dumpable
{

    //Sandra entity is a pair of two triplets.
    // Like concept is a dog
    // 2. concept contained in dogFile



    /* @var $conceptManager ConceptManager */
    private $factoryTable;
    public $populated; //is the factory populated from database
    public $foreignPopulated = false; //is full if we got all the entities without the filter
    public $populatedFull = false; //is full if we got all the entities without the filter
    private $su = true; //is the factory super user status
    private $indexUnid;
    public $tripletRetreived;




    public $entityReferenceContainer = 'contained_in_file';

    public $sandraReferenceMap =array();

    public $entityIndexMap;
    public $refMap;
    public $maxIndex;
    public $foreignAdapter;
    /* @var $foreignAdapter ForeignEntityAdapter */

    private $fuseForeignConcept;
    /* @var $fuseForeignConcept ForeignConcept */
    private $fuseLocalConcept;
    /* @var $fuseForeignConcept Concept */

    public $newEntities = array();



    private $brotherVerb;
    private $brotherTarget;

    public $brotherEntities ; //to delete ?
    public $brotherEntitiesArray = array();
    public $brotherMap ;
    public $brotherEntitiesVerified = null;

    public $joinedFactoryArray = array(); /* @var $joinedFactoryArray EntityFactory[] */
    public $conceptArray = array(); /* if we have a list of concept already  */


    public $sc;


    public function setIndex($index)
    {

        if (!is_numeric($index)) {
            $unidIndex = $this->sc->get($index);
            $this->indexShortname = $index;
        } else {
            $unidIndex = $index;
            $this->indexShortname = $this->sc->get($index);
        }

        $this->indexUnid = $unidIndex;

    }


    public function __construct($entityIsa, $entityContainedIn, System $system)
    {

        parent::__construct($system);

        $this->entityIsa = $entityIsa;
        $this->entityContainedIn = $entityContainedIn;
        $this->factoryTable = $system->tableSuffix;

        $this->indexUnid = $system->systemConcept->get($this->indexShortname);

        $this->system = $system;
        $this->sc = $system->systemConcept;

        $this->conceptManager = new ConceptManager($this->su, $this->system);

        $this->initDisplayer();

        $this->refMap = array();




    }

    public function setSuperUser($boolean)
    {

        $this->su = $boolean;
        $this->conceptManager = new ConceptManager($this->su, $this->system);

    }


    public function mergeRefFromBrotherEntities($brotherVerb, $brotherTarget)
    {

        $this->brotherVerb = $this->sc->get($brotherVerb);
        $this->brotherTarget = $this->sc->get($brotherTarget);

    }




    /**
     * @return Entity[]
     */
    public function populateLocal($limit = 10000,$offset = 0,$asc='ASC')
    {

        $entityArray = array();
        $filter = array();

        //do we filter by isa
        if ($this->entityIsa) {
            $filter = array(array('lklk' => $this->sc->get('is_a'), 'lktg' => $this->sc->get($this->entityIsa)));

        }
        //we build a filter in our query
        if(is_array($this->tripletfilter)) {
            foreach ($this->tripletfilter as $filterValue) {

                $buildFilter = array();
                $filterVerb = $filterValue['verbConceptId'];
                $filterTarget = $filterValue['targetConceptId'];

                $buildFilter['lklk'] = $filterVerb;
                $buildFilter['lktg'] = $filterTarget;

                if ($filterValue['exclusion'] == true) {

                    $buildFilter['exclusion'] = 1;
                }

                $filter[] = $buildFilter;
            }
        }

        if ($filter !== 0) {
            $this->conceptManager->setFilter($filter);
        }

        $entityReferenceContainer = $this->sc->get($this->entityReferenceContainer);

        //we don't have preselected concept yet
        if(empty($this->conceptArray)){

            $this->conceptManager->getConceptsFromLinkAndTarget($entityReferenceContainer, $this->sc->get($this->entityContainedIn), $limit, $asc, $offset);
        }
        else {

            $this->conceptManager->getConceptsFromArray($this->conceptArray);
        }

        $refs = $this->conceptManager->getReferences($entityReferenceContainer, $this->sc->get($this->entityContainedIn));

        if ($this->brotherVerb or $this->brotherTarget) {
            $mergedRefs = $this->conceptManager->getReferences($this->brotherVerb, $this->brotherTarget);
        }


        $this->populated = true;
        $this->populatedFull = true;
        $sandraReferenceMap = array();

        //Each concept
        if (is_array($refs)) {
            foreach ($refs as $key => $value) {

                $concept = $this->system->conceptFactory->getConceptFromId($key);
                $entityId = $value['linkId'];
                $refArray = null;


                //each reference
                foreach ($value as $refConceptUnid => $refValue) {

                    //escape if reference is not a concept id
                    if (!is_numeric($refConceptUnid))
                        continue;

                    //we are builiding the auto increment index if any
                    if ($refConceptUnid == $this->indexUnid) {
                        if ($refValue > $this->maxIndex) {
                            $indexFound = $refValue;
                            $this->maxIndex = $refValue;
                        }
                    }

                    $refArray[$refConceptUnid] = $refValue;

                    //we add the reference in the factory reference map
                    $sandraReferenceMap[$refConceptUnid] = $this->system->conceptFactory->getConceptFromId($refConceptUnid);
                }

                //there are ref to be merged
                if (isset($mergedRefs[$key])) {

                    foreach ($mergedRefs[$key] as $mergeConceptId => $refValueMerged) {

                        //the reference not already exist in entity
                        if (!$refArray[$mergeConceptId]) {

                            $refArray[$mergeConceptId] = $refValueMerged;

                            //we add the reference in the factory reference map
                            $sandraReferenceMap[$refConceptUnid] = $this->system->conceptFactory->getConceptFromId($mergeConceptId);
                        }
                    }
                }
                $classname = $this->generatedEntityClass;

                //do we have a hardcoded class in the datagraph
                if (isset ($refArray[$this->system->systemConcept->get('class_name')])){

                    $classname = $refArray[$this->system->systemConcept->get('class_name')] ;

                }

                $entityVerb = $this->system->conceptFactory->getConceptFromShortnameOrId($entityReferenceContainer);
                $entityTarget = $this->system->conceptFactory->getConceptFromShortnameOrId($this->entityContainedIn);
                $entity = new $classname($concept, $refArray, $this, $entityId, $entityVerb, $entityTarget, $this->system);
                //$entity = new Entity($concept,$refArray,$this,$entityId,$entityVerb,$entityTarget,$this->system);
                $entityArray[$key] = $entity;

                if (isset($indexFound)) {

                    //if entity of this factory have index
                    $this->entityIndexMap[$indexFound] = $entity;
                }
            }
        }

        $this->addNewEtities($entityArray, $sandraReferenceMap);

        return $this->entityArray;

    }
    /**
     * @return Entity[]
     */
    public function populateBrotherEntities($verb,$target=null)
    {

        $entityArray = array();
        if($target===null) $target = 0 ;


        //has brother already been verified ?
        // if (isset($this->brotherEntitiesVerified[$this->sc->get($verb)][$target]))
        //   return



        $refs = $this->conceptManager->getReferences($this->sc->get($verb), $this->sc->get($target),null,0,1);

        $sandraReferenceMap = array();

        //Each concept
        if (is_array($refs)) {
            foreach ($refs as $keyConcept => $valueArray) {
                foreach ($valueArray as $linkId => $value) {

                    $concept = $this->system->conceptFactory->getConceptFromId($keyConcept);
                    $entityId = $linkId;
                    $refArray = null;
                    $entityData[$entityId]['idConceptTarget'] = $value['idConceptTarget'];


                    //each reference
                    foreach ($value as $refConceptUnid => $refValue) {



                        //escape if reference is not a concept id
                        if (!is_numeric($refConceptUnid))
                            continue;


                        $refArray[$entityId][$refConceptUnid] = $refValue;

                        //we add the reference in the factory reference map
                        $sandraReferenceMap[$refConceptUnid] = $this->system->conceptFactory->getConceptFromId($refConceptUnid);
                    }

                    //we build resulting entities
                    foreach ($refArray as $entityId => $entityRefs) {

                        $entityVerb = $this->system->conceptFactory->getConceptFromShortnameOrId($verb);
                        $entityTarget = $this->system->conceptFactory->getConceptFromId($entityData[$entityId]['idConceptTarget']);

                        $entity = new Entity($concept, $entityRefs, $this, $entityId, $entityVerb, $entityTarget, $this->system);


                        $entityArray[$entityId] = $entity;


                    }
                }



            }
        }

        $this->addBrotherEntities($entityArray, $sandraReferenceMap);
        $this->brotherEntitiesVerified[$this->sc->get($verb)][$target];
        return $this->entityArray;

    }


    public function populatePartialEntity($referenceOnVerb, $referenceOnTarget)
    {


    }

    public function addNewEtities($entityArray, $referenceMap)
    {

        if (!is_array($entityArray)) return;
        // if (!is_array($referenceMap)) return;

        if ($this->entityArray) {

            $this->entityArray = $this->entityArray + $entityArray;
            if (is_array($referenceMap)) {
                //reference map should be investigated
                //$this->sandraReferenceMap = $this->sandraReferenceMap + $referenceMap;
            }
        } else {

            $this->entityArray = $entityArray;
            $this->sandraReferenceMap = $referenceMap;
        }

        //we nullify the fact that we loaded triplets
        $this->tripletRetreived = false;

    }

    public function joinFactory($verbJoin, EntityFactory $factory)
    {

        $verbJoinConceptId = CommonFunctions::somethingToConceptId($verbJoin,$this->system);
        $this->joinedFactoryArray[$verbJoinConceptId] = $factory;

    }

    public function joinPopulate()
    {
        $conceptIdList = array();
        $this->getTriplets();
        //we cycle trough all entities subject concepts
        if(!is_array($this->entityArray)) return ;

        foreach ($this->entityArray as $key => $entity) {
            $triplets = $entity->subjectConcept->tripletArray ;

            foreach ($this->joinedFactoryArray as $conceptVerbId => $value) {
                if (!isset($triplets[$conceptVerbId])){continue;}
                foreach ($triplets[$conceptVerbId] as $targetConceptId) {
                    $conceptIdList[$conceptVerbId][] = $targetConceptId;
                }
            }
        }

        //then we inject all concept into the joined factories
        foreach ($this->joinedFactoryArray as $verbUnid => $factory)
        {
            if (!isset($conceptIdList[$verbUnid])){continue ;}
            /* @var $factory EntityFactory */
            $factory->conceptArray = $conceptIdList[$verbUnid] ;
            $factory->populateLocal();
        }
    }

    public function addBrotherEntities($entityArray, $referenceMap)
    {

        if (!is_array($entityArray)) return;
        if (!is_array($referenceMap)) return;

        foreach ($entityArray as $entity) {
            /** @var Entity $entity */
            $subject = $entity->subjectConcept->idConcept ;
            $verb = $entity->verbConcept->idConcept ;
            $target = $entity->targetConcept->idConcept ;

            $this->brotherEntitiesArray[$subject][$verb][$target] = $entity ;

        }


    }

    public function foreignPopulate(ForeignEntityAdapter $foreignAdapter = null,$limit = 0)
    {


        if ($foreignAdapter == null) {
            $foreignAdapter = $this->foreignAdapter;
        }

        $foreignAdapter->populate($limit);


        $this->addNewEtities($foreignAdapter->returnEntities(), $foreignAdapter->getReferenceMap());


        $this->foreignPopulated = true;

        $this->foreignAdapter = $foreignAdapter;


    }

    public function return2dArray()
    {
        $returnArray = array();

        foreach ($this->entityArray as $key => $entity) {


            foreach ($entity->entityRefs as $referenceObject) {


                $refConceptUnid = $referenceObject->refConcept->idConcept;
                $refConceptName = $referenceObject->refConcept->getDisplayName('system');


                $returnArray[$key][$refConceptName] = $referenceObject->refValue;

            }

        }

        return $returnArray;

    }



    public function createNewWithAutoIncrementIndex($dataArray, $linkArray = null)
    {
        if (!$this->populatedFull) {
            $this->populateLocal();

        }

        $this->maxIndex++;
        $dataArray[$this->indexShortname] = $this->maxIndex;

        return $this->createNew($dataArray, $linkArray);

    }

    public function setFuseForeignOnRef($foreignRef, $localRefName,$vocabulary = null)
    {
        /* doenst work yet

        if (is_array($vocabulary)) {
            $this->foreignAdapter->adaptToLocalVocabulary($vocabulary);
        }
        $this->foreignAdapter->addToLocalVocabulary($foreignRef,$localRefName); //Caution I reactivated that but I don't know why
*/
        $this->system->systemConcept->get($localRefName);

        $localRefConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($localRefName);
        $foreignRefConcept = $this->system->conceptFactory->getForeignConceptFromId($foreignRef);
        $this->fuseForeignConcept = $foreignRefConcept;
        $this->fuseLocalConcept = $localRefConcept;

    }

    public function mergeEntities($foreignRef, $localRefName)
    {

        //$this->foreignAdapter->addToLocalVocabulary($foreignRef,$localRefName);

        $localRefConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($localRefName);
        $foreignRefConcept = $this->system->conceptFactory->getForeignConceptFromId($foreignRef);
        $this->fuseForeignConcept = $foreignRefConcept;
        $this->fuseLocalConcept = $localRefConcept;

    }

    /**
     *
     */
    public function fuseRemoteEntity()
    {

        /* @var $localRefConcept $this->fuseForeignConcept */
        /* @var $localOnThisRef Entity */

        $localRefConcept = $this->fuseLocalConcept;
        $foreignRefConcept = $this->fuseForeignConcept;

        if (!$localRefConcept)
            return;

        //$foreignRef = $foreignRefConcept->getDisplayName() ;

        //get the map of reference that should be similar
        $localRefMap = $this->getRefMap($localRefConcept);
        //$foreignRefMap = $this->foreignAdapter->getRefMap($foreignRef);

        //return if no refmap
        if (empty($localRefMap))
            return ;

        foreach ($localRefMap as $key => $value) {


            $localOnThisRef = array();
            $foreignOnThisRef = array();


            //do we have a foreign and a local ?
            foreach ($value as $index => $entityObj) {

                $fused = false;

                if ($entityObj instanceof ForeignEntity) $foreignOnThisRef[] = $entityObj;
                elseif ($entityObj instanceof Entity) $localOnThisRef[] = $entityObj;
            }

            if (count($foreignOnThisRef) > 1) die ("Non unique reference on $key multiple foreign instance");
            if (count($localOnThisRef) > 1) die ("Non unique reference on $key multiple local instance");

            /* @var $foreignOnThisRef ForeignEntity */
            /* @var $localOnThisRef Entity */


            $localOnThisRef = reset($localOnThisRef);
            $foreignOnThisRef = reset($foreignOnThisRef);


            if (isset($localOnThisRef->entityRefs) && isset($foreignOnThisRef->entityRefs)) {

                $localOnThisRef->entityRefs = $localOnThisRef->entityRefs + $foreignOnThisRef->entityRefs;

                $fused = true;

            }
            $key = array_search($foreignOnThisRef, $this->entityArray);

            //if fused remove the remote entity
            if ($fused) {
                unset($this->entityArray[$key]);
            }
        }

        //unset the entitymap
        //unset($this->refMap);
        //unset($this->entityIndexMap);

    }

    public function createOrUpdateOnIndex($indexValue, $dataArray, $linArray = null)
    {

        $this->verifyPopulated(true);


        //does the index exists ?
        if ($this->entityIndexMap[$indexValue]) {


        }

    }


    public function update(Entity $entity, $dataArray, $linkArray = null)
    {
        foreach ($dataArray ? $dataArray : array() as $keyData => $valueData) {


            $localData = $entity->get($keyData);
            if ($localData != $valueData) {

                $entity->createOrUpdateRef($keyData, $valueData);


            }

        }

        //do we have brother entities ?
        foreach ($linkArray ? $linkArray : array() as $verb => $valueToTarget) {

            $valueTargetArray = array();

            if ($valueToTarget instanceof Entity) {
                $valueToTarget = $valueToTarget->subjectConcept->idConcept;

            }

            //Each target
            if (!is_array($valueToTarget)) {
                //in case we have only one target for a link
                $valueTargetArray[$valueToTarget] = $valueToTarget;
            } else {
                $valueTargetArray = $valueToTarget;
            }

            foreach ($valueTargetArray ? $valueTargetArray : array() as $targetConcept => $targetArray) {
                if (!($this->tripletRetreived)) $this->getTriplets();


                if (!is_array($targetArray)) {


                    $targetArray = [$targetArray];


                }

                foreach ($targetArray as $singleTarget) {


                    if (is_array($targetArray)) {
                        $this->system->systemError(777, 'entityFactory', '2', 'Update on brother entity reference not supported');
                        //TODO update brother reference

                    } else {
                        $targetConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($singleTarget);

                        if (!isset($entity->subjectConcept->tripletArray[$targetConcept->idConcept]))
                            //it's a simple data link without refrence
                            $entity->setBrotherEntity($verb, $targetConcept, null);

                    }
                }

            }
        }

    }

    public function createOrUpdateOnReference($refShortname, $refValue, $dataArray, $linkArray = null)
    {

        $this->verifyPopulated(true);
        $referenceObject = $this->system->conceptFactory->getConceptFromId($this->sc->get($refShortname));
        $refmap = $this->getRefMap($referenceObject);

        //Now we find if the object exists

        if ($refmap[$refValue]) {
            /** @var Entity $foundEntity */

            $foundEntity = end($refmap[$refValue]);


            $this->update($foundEntity, $dataArray, $linkArray);


        } //concept doens't exists
        else {
            $dataArray[$refShortname] = $refValue;

            $this->createNewWithAutoIncrementIndex($dataArray, $linkArray);
        }
    }

    //the ref map is an array that list reference as map so it's easier to acess them
    public function getRefMap($conceptObject,$forceRefresh = false)
    {
        if(empty($this->entityArray)) return null ;

        $valOfConcept = $conceptObject->idConcept;
        if (!isset($this->refMap[$conceptObject->idConcept])) {

            foreach ($this->entityArray as $value) {

                //id of the reference
                $valOfConcept = $conceptObject->idConcept;
                if (!isset  ($value->entityRefs[$valOfConcept])) continue ;
                $valueOfThisRef = $value->entityRefs[$valOfConcept]->refValue;

                $this->refMap[$valOfConcept][$valueOfThisRef][] = $value;

            }
        }

        if (!isset($this->refMap[$valOfConcept]))
            return null ;

        if (!isset($valOfConcept))
            return null ;

        return $this->refMap[$valOfConcept];
    }


    public function createNew($dataArray, $linArray = null): Entity
    {
        $conceptId = DatabaseAdapter::rawCreateConcept("A " . $this->entityIsa, $this->system,false);
        $addedRefMap = array();

        $addedReferenceMap = array();

        if ($this->entityIsa) {

            DatabaseAdapter::rawCreateTriplet($conceptId, $this->sc->get('is_a'), $this->sc->get($this->entityIsa), $this->system,false);
        }

        $entityReferenceContainer = $this->sc->get($this->entityReferenceContainer);
        $link = DatabaseAdapter::rawCreateTriplet($conceptId, $entityReferenceContainer, $this->sc->get($this->entityContainedIn), $this->system,0,false);

        if (isset($_GET['trigger'])) {
            DatabaseAdapter::rawCreateReference($this->sc->get('calledByTrigger'), $link, $_GET['trigger'], $this->system,false);
        }

        if (isset($userUNID)) {
            DatabaseAdapter::rawCreateReference($link, $this->sc->get('creator'), $userUNID, $this->system,false);
        }

        DatabaseAdapter::rawCreateReference($link, $this->sc->get('creationTimestamp'), time(), $this->system, false);

        //each reference
        foreach ($dataArray as $key => $value) {
            if(is_array($value)) continue ;
            if (!is_numeric($key)) {

                $key = $this->sc->get($key);
            }

            if ($value instanceof Reference){
                $value = $value->refValue ;
            }

            DatabaseAdapter::rawCreateReference($link, $key, $value, $this->system,false);
            $addedRefMap[$key] = $value ;
            $addedReferenceMap[$key] = $this->system->conceptFactory->getConceptFromId($key) ;
        }

        if (is_array($linArray)) {
            //each link verb
            foreach ($linArray as $verb => $valueToTarget) {
                $valueTargetArray = array();

                if ($valueToTarget instanceof Entity){
                    $valueToTarget = $valueToTarget->subjectConcept->idConcept ;

                }

                //Each target
                if(!is_array($valueToTarget)){
                    //in case we have only one target for a link
                    $valueTargetArray[$valueToTarget] =$valueToTarget;
                }

                else{
                    $valueTargetArray = $valueToTarget;
                }
                foreach ($valueTargetArray as $target => $targetNameOrArray) {
                    $extraRef = array();

                    //if the link array contains an array then there are reference to be added
                    if (is_array($targetNameOrArray)) {

                        $targetName = $target;
                        $extraRef = $targetNameOrArray;
                    } else {
                        $targetName = $target;
                    }

                    if ($targetNameOrArray instanceof Entity){
                        $targetName = $targetNameOrArray->subjectConcept->idConcept ;
                    }

                    $linkId = DatabaseAdapter::rawCreateTriplet($conceptId, $this->sc->get($verb), $this->sc->get($targetName), $this->system,false);
                    //Now we will add reference on additional links if any
                    if (!empty($extraRef)) {
                        foreach ($extraRef as $refname => $refValue) {
                            DatabaseAdapter::rawCreateReference($linkId, $this->sc->get($refname), $refValue, $this->system, false);

                        }
                    }
                }
            }
        }

        $conceptContainedIn = $this->system->conceptFactory->getConceptFromId($this->sc->get($this->entityContainedIn)) ;
        $conceptContainerConcept = $this->system->conceptFactory->getConceptFromId($this->sc->get($this->entityReferenceContainer)) ;

        DatabaseAdapter::commit();

        $classname = $this->generatedEntityClass ;

        //do we have a hardcoded class in the datagraph
        if (isset ($addedRefMap[$this->system->systemConcept->get('class_name')])){

            $classname = $addedRefMap[$this->system->systemConcept->get('class_name')] ;

        }

        $createdEntity = new $classname($this->system->conceptFactory->getConceptFromId($conceptId),$dataArray,$this,$link,$conceptContainerConcept,$conceptContainedIn,$this->system);
        $concept = $createdEntity->subjectConcept;

        //we need to add this new concept in the manager
        $this->conceptManager->conceptArray['conceptStartList'][] = $concept->idConcept;
        $this->conceptManager->concepts[] = $concept;



        foreach ($addedRefMap as $key => $value){

            $this->refMap[$key][$value][] = $createdEntity ;
        }

        $this->addNewEtities(array($createdEntity->subjectConcept->idConcept=>$createdEntity),$addedReferenceMap);

        return $createdEntity ;

    }

    public function verifyPopulated($fullPopulatedRequired = false)
    {

        try {
            if (!$this->populated or !$this->populatedFull) {
                throw new Exception('Unpopulated Entity Factory');
            } else if ($fullPopulatedRequired && $this->populatedFull == false) {
                throw new Exception('Factory not fully populated');

            }

        } catch (Exception $e) {
            echo 'Message: ' . $e->getMessage();
        }
    }

    public function serializeFactoryTemplate(): String
    {

        $lightFactory = clone $this;

        $lightFactory->entityArray = null;
        $lightFactory->sandraReferenceMap = null;
        $lightFactory->refMap = null;
        $lightFactory->entityIndexMap = null;

        $lightFactory->populated = null;
        $lightFactory->populatedFull = null;
        $lightFactory->conceptManager = null;
        $lightFactory->foreignPopulated = null;

        $lightFactory->system = null;

        $serializedFactories = array();


        foreach ($lightFactory->joinedFactoryArray as $factory)
        {
            /** @var EntityFactory $factory */

            $serializedFactories[] = $factory->serializeFactoryTemplate();

        }

        $lightFactory->joinedFactoryArray = $serializedFactories ;

        return serialize($lightFactory);
    }

    public function saveEntitiesNotInLocal()
    {
        $newEntities = array();
        //we need to verify if everything is polpulated and fused.
        $this->verifyPopulated(1);

        foreach ($this->entityArray as $key => $value) {

            //it's a new entity
            if ($value instanceof ForeignEntity) {
                $newEntities[] = $value;

                $value->save($this);
            }
        }
        return $newEntities;
    }

    public function getAllWith($referenceName, $referenceValue)
    {

        if ($referenceName == null) {
            $this->system->systemError(400, 'EntityFactory', 'Critical',
                "getAllWith $referenceValue reference concept is null");

        }

        //do we have local concept or a foreign ?
        if ($this instanceof ForeignEntityAdapter) {
            $referenceConcept = $this->system->conceptFactory->getForeignConceptFromId($referenceName);
        } else {
            $referenceConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($referenceName);
        }

        $refmap = $this->getRefMap($referenceConcept);

        if (is_array($refmap) && key_exists($referenceValue, $refmap)) {

            //If we have a single entity make sure to return an array
            if (!is_array($refmap[$referenceValue])) {
                return array($refmap[$referenceValue]);
            }

            return $refmap[$referenceValue];
        } //the factory is not populated so we look in database
        else if (!$this->populated) {

            $entityReferenceContainer = $this->sc->get($this->entityReferenceContainer);
            $referenceConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($referenceName);
            $link = $this->system->conceptFactory->getConceptFromShortnameOrId($this->entityReferenceContainer);
            $target = $this->system->conceptFactory->getConceptFromShortnameOrId($this->entityContainedIn);

            //First we need to know if exist already in the factory

            $searchResults = DatabaseAdapter::searchConcept($referenceValue,
                $referenceConcept->idConcept, $this->system, $link->idConcept, $target->idConcept, '', '', 1);

            if (is_array($searchResults)) {
                foreach ($searchResults as $resultSet) {

                    $concept = $this->system->conceptFactory->getConceptFromId($resultSet['idConceptStart']);

                    $classname = $this->generatedEntityClass;

                    $entityVerb = $this->system->conceptFactory->getConceptFromShortnameOrId($entityReferenceContainer);
                    $entityTarget = $this->system->conceptFactory->getConceptFromShortnameOrId($this->entityContainedIn);

                    $entityId = $resultSet['entityId'];

                    $refArray[$referenceConcept->idConcept] = $resultSet['referenceValue'];

                    $entity = new $classname($concept, $refArray, $this, $entityId, $entityVerb, $entityTarget, $this->system);
                    /** @var Entity $entity */
                    //$entity = new Entity($concept,$refArray,$this,$entityId,$entityVerb,$entityTarget,$this->system);
                    $referenceCreated = $entity->getReference($referenceName);
                    $referenceCreated->refEntity = $entity;
                    $this->refMap[$referenceConcept->idConcept][$referenceValue][] = $entity ;

                    $this->entityArray[] = $entity;

                    //we need to add the concept in the concept Manager
                    $this->conceptManager->conceptArray['conceptStartList'][] =  $concept->idConcept ;
                    $this->conceptManager->concepts[] =  $concept ;
                }

                $refmap = $this->getRefMap($referenceConcept);
                return $refmap[$referenceValue];
            }
            return null;
        }
        return null;
    }

    public function dumpMeta()
    {

        $factoryData['status']['populated'] = $this->populated ;
        $factoryData['is_a'] = $this->entityIsa;
        $factoryData['path'][$this->entityReferenceContainer] = $this->entityContainedIn;

        $entities = array();

        if (!empty($this->entityArray)) {
            foreach ($this->entityArray as $key => $entity) {
                $entities[] = $entity->dumpMeta();
            }
        }
        $factoryData['entities'] = $entities ;

        $refMap = array();
        //print_r($this->refMap);

        if (is_array($this->refMap)) {

            foreach ($this->refMap as $conceptId => $valueArray) {
                foreach ($valueArray as $valueOfIndex => $entityArray) {
                    foreach ($entityArray as $entityCounter => $entity) {

                        $conceptObject = $this->system->conceptFactory->getConceptFromId($conceptId);
                        $conceptMeta = $conceptObject->dumpMeta();

                        $refMap[$conceptMeta][$valueOfIndex][$entityCounter] = $entity->dumpMeta();
                    }
                }
            }
        }
        $factoryData['refMap'] = $refMap;
        $meta['factory'] = $factoryData;
        return $meta;
    }

    public function getTriplets()
    {
        if ($this->tripletRetreived == false) {

            $tripletArray = $this->conceptManager->getTriplets();
            if (is_array($tripletArray)) {

                foreach ($tripletArray as $keyConcept => $triplet) {

                    $concept = $this->system->conceptFactory->getConceptFromId($keyConcept);
                    $concept->tripletArray = $triplet;

                    //We look at revese triplets
                    foreach ($triplet as $verb => $target) {
                        foreach ($target as $idConceptTarget) {

                            $conceptTarget = $this->system->conceptFactory->getConceptFromId($idConceptTarget);
                            $conceptTarget->reverseTriplet[$verb] = $keyConcept;
                        }
                    }
                }
            }
        }
        $this->tripletRetreived = true;
    }




}