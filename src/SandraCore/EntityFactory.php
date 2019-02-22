<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:49
 */

namespace SandraCore;


use SandraCore\displayer\Displayer;

class EntityFactory extends FactoryBase implements Dumpable
{

    //Sandra entity is a pair of two triplets.
    // Like concept is a dog
    // 2. concept contained in dogFile

    private $entityIsa;
    protected $entityContainedIn;
    public $conceptManager;
    /* @var $conceptManager ConceptManager */
    private $factoryTable;
    private $populated; //is the factory populated from database
    private $foreignPopulated = false; //is full if we got all the entities without the filter
    private $populatedFull = false; //is full if we got all the entities without the filter
    private $su = true; //is the factory super user status
    private $indexUnid;

    protected $generatedEntityClass = '\SandraCore\Entity';

    /* @var $entityArray Entity[] */
    public $entityArray;
    public $entityReferenceContainer = 'contained_in_file';

    public $sandraReferenceMap;
    public $indexShortname = 'index';
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

    public $factoryIdentifier = 'noNameFactory';

    private $brotherVerb;
    private $brotherTarget;

    public $brotherEntities ;
    public $brotherMap ;

    public $system;
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
    public function populateLocal($limit = 10000)
    {


        $entityArray = array();

        //do we filter by isa
        if ($this->entityIsa) {
            $filter = array(array('lklk' => $this->sc->get('is_a'), 'lktg' => $this->sc->get($this->entityIsa)));
            $this->conceptManager->setFilter($filter);

        }

        $entityReferenceContainer = $this->sc->get($this->entityReferenceContainer);


        $this->conceptManager->getConceptsFromLinkAndTarget($entityReferenceContainer, $this->sc->get($this->entityContainedIn), $limit);
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
    public function populateBotherEntiies($verb,$target)
    {


        $entityArray = array();



        $refs = $this->conceptManager->getReferences($this->sc->get($verb), $this->sc->get($target));







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





                $entityVerb = $this->system->conceptFactory->getConceptFromShortnameOrId($verb);
                $entityTarget = $this->system->conceptFactory->getConceptFromShortnameOrId($target);


                $entity = new Entity($concept, $refArray, $this, $entityId, $entityVerb, $entityTarget, $this->system);
                //$entity = new Entity($concept,$refArray,$this,$entityId,$entityVerb,$entityTarget,$this->system);



                $entityArray[$key] = $entity;

                if (isset($indexFound)) {

                    //if entity of this factory have index
                    $this->entityIndexMap[$indexFound] = $entity;
                }


            }
        }


        $this->addBrotherEntities($entityArray, $sandraReferenceMap);

        return $this->entityArray;


    }


    public function populatePartialEntity($referenceOnVerb, $referenceOnTarget)
    {


    }

    public function addNewEtities($entityArray, $referenceMap)
    {

        if (!is_array($entityArray)) return;
        if (!is_array($referenceMap)) return;

        if ($this->entityArray) {


            $this->entityArray = $this->entityArray + $entityArray;
            $this->sandraReferenceMap = $this->sandraReferenceMap + $referenceMap;

        } else {

            $this->entityArray = $entityArray;
            $this->sandraReferenceMap = $referenceMap;
        }

    }

    public function addBrotherEntities($entityArray, $referenceMap)
    {

        if (!is_array($entityArray)) return;
        if (!is_array($referenceMap)) return;


        //die("stop");
        if ($entityArray) {


            $this->entityArray = $this->entityArray + $entityArray;
            $this->sandraReferenceMap = $this->sandraReferenceMap + $referenceMap;

        } else {

            $this->entityArray = $entityArray;
            $this->sandraReferenceMap = $referenceMap;
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

    public function setFuseForeignOnRef($foreignRef, $localRefName)
    {

        //$this->foreignAdapter->addToLocalVocabulary($foreignRef,$localRefName);

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


    public function update($entity, $dataArray)
    {


        //if()

    }

    public function createOrUpdateOnReference($refShortname, $refValue, $dataArray, $linkArray = null)
    {

        $this->verifyPopulated(true);

        $referenceObject = $this->system->conceptFactory->getConceptFromId(getSC($refShortname));

        $refmap = $this->getRefMap($referenceObject);

        //Now we find if the object exists


        if ($refmap[$refValue]) {


        } //concept doens't exists
        else {

            $this->createNewWithAutoIncrementIndex($dataArray, $linkArray);

        }


    }

    //the ref map is an array that list reference as map so it's easier to acess them
    public function getRefMap($conceptObject)
    {
        //print_r($conceptObject->dumpMeta());
        //print_r($this->refMap);
        //die();
        $valOfConcept = $conceptObject->idConcept;
        if (!isset($this->refMap[$conceptObject->idConcept])) {

            foreach ($this->entityArray as $value) {

                //id of the reference


                $valOfConcept = $conceptObject->idConcept;
                $valueOfThisRef = $value->entityRefs[$valOfConcept]->refValue;

                //echo " of concept $valOfConcept -> $valueOfThisRef \n";


                $this->refMap[$valOfConcept][$valueOfThisRef][] = $value;


            }

        }

        if (!isset($this->refMap[$valOfConcept]))
            return null ;

        if (!isset($valOfConcept))
            return null ;


        return $this->refMap[$valOfConcept];


    }


    public function createNew($dataArray, $linArray = null)
    {


        $conceptId = DatabaseAdapter::rawCreateConcept("A " . $this->entityIsa, $this->system,false);


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

            if (!is_numeric($key)) {

                $key = $this->sc->get($key);
            }

            DatabaseAdapter::rawCreateReference($link, $key, $value, $this->system,false);

        }


        if (is_array($linArray)) {
            //each link verb
            foreach ($linArray as $verb => $valueTargetArray) {
                //Each target
                foreach ($valueTargetArray as $target => $targetNameOrArray) {
                    $extraRef = array();

                    //if the link array contains an array then there are reference to be added
                    if (is_array($targetNameOrArray)) {

                        $targetName = $target;
                        $extraRef = $targetNameOrArray;

                        

                    } else {
                        $targetName = $target;

                    }

                    $linkId = DatabaseAdapter::rawCreateTriplet($conceptId, $this->sc->get($verb), $this->sc->get($targetName), $this->system,false);



                    //Now we will add reference on additional links if any
                    if (!empty($extraRef)) {

                        foreach ($extraRef as $refname => $refValue) {



                            DatabaseAdapter::rawCreateReference($linkId, $this->sc->get($refname), $refValue, $this->system, false);



                        }
                        //DatabaseAdapter::commit();

                    }
                }

                //we are comiting references all at one



                //DatabaseAdapter::rawCreateReference($this->sc->get($key), $conceptId, $this->sc->get($value), $this->system);
            }

        }
        
        $conceptContainedIn = $this->system->conceptFactory->getConceptFromId($this->sc->get($this->entityContainedIn)) ;
        $conceptContainerConcept = $this->system->conceptFactory->getConceptFromId($this->sc->get($this->entityReferenceContainer)) ;

        DatabaseAdapter::commit();
        
        $createdEntity = new Entity($this->system->conceptFactory->getConceptFromId($conceptId),$dataArray,$this,$link,$conceptContainerConcept,$conceptContainedIn,$this->system);
        
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

        $lightFactory = $this;

        $lightFactory->entityArray = null;
        $lightFactory->sandraReferenceMap = null;
        $lightFactory->refMap = null;
        $lightFactory->entityIndexMap = null;

        $lightFactory->populated = null;
        $lightFactory->populatedFull = null;
        $lightFactory->conceptManager = null;
        $lightFactory->foreignPopulated = null;

        return serialize($lightFactory);


    }

    public function saveEntitiesNotInLocal()
    {
        $newEntities = array();

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


        if ($this->populated) {

            $referenceConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($referenceName);

            $refmap = $this->getRefMap($referenceConcept);
            
            if (is_array($refmap) && key_exists($referenceValue,$refmap)){

                //If we have a single entity make sure to return an array
                if (!is_array($refmap[$referenceValue])){
                    return array($refmap[$referenceValue]);
                }

                return $refmap[$referenceValue];
            }
            else {

                return null;
            }

           
        }

        die("not full populated");
        


    }

    public function dumpMeta()
    {

        $factoryData['status']['populated'] = $this->populated ;
       $factoryData['is_a'] = $this->entityIsa;
       $factoryData['path'][$this->entityReferenceContainer] = $this->entityContainedIn;

        $entities = array();
       
       if (!empty($this->entityArray)) {
           foreach ($this->entityArray as $key => $entity) {
               $entities[] = $entity->dumpMeta() ;

           }
          
       }
        $factoryData['entities'] = $entities ;

       $refMap = array();
       //print_r($this->refMap);

       if(is_array($this->refMap)) {

           foreach ($this->refMap as $conceptId => $valueArray) {
               foreach ($valueArray as $valueOfIndex => $entityArray) {
                   foreach ($entityArray as $entityCounter => $entity) {

                       $conceptObject = $this->system->conceptFactory->getConceptFromId($conceptId);
                       $conceptMeta = $conceptObject->dumpMeta();

                       $refMap[$conceptMeta][$valueOfIndex] = $entity->dumpMeta();
                   }
               }
           }
       }
        $factoryData['refMap'] = $refMap ;

       $meta['factory'] =  $factoryData ;


       
       

       return $meta ;
        

    }

    public function getTriplets()
    {


        $tripletArray = $this->conceptManager->getTriplets();

        foreach ($tripletArray as $keyConcept => $triplet) {

            $concept = $this->system->conceptFactory->getConceptFromId($keyConcept);
            $concept->tripletArray = $triplet;

            //We look at revese triplet
            foreach ($triplet as $verb => $target) {
                foreach ($target as $idConceptTarget) {

                    $conceptTarget = $this->system->conceptFactory->getConceptFromId($idConceptTarget);
                    $conceptTarget->reverseTriplet[$verb] = $keyConcept;

                }


            }

        }

    }


}