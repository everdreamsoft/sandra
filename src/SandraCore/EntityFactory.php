<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:49
 */

namespace SandraCore;


class EntityFactory
{

    //Sandra entity is a pair of two triplets.
    // Like concept is a dog
    // 2. concept contained in dogFile

    private $entityIsa ;
    protected $entityContainedIn ;
    public $conceptManager ; /* @var $conceptManager ConceptManager */
    private $factoryTable ;
    private $populated ; //is the factory populated from database
    private $foreignPopulated = false; //is full if we got all the entities without the filter
    private $populatedFull = false; //is full if we got all the entities without the filter
    private $su = true; //is the factory super user status
    private $indexUnid ;

    protected $generatedEntityClass = 'SandraEntity' ;

    public $entityArray ;
    public $entityReferenceContainer = 'contained_in_file' ;

    public $sandraReferenceMap ;
    public $indexShortname = 'index' ;
    public $entityIndexMap ;
    public $refMap ;
    public $maxIndex ;
    public $foreignAdapter ; /* @var $foreignAdapter ForeignEntityAdapter */

    private $fuseForeignConcept ; /* @var $fuseForeignConcept ForeignConcept */
    private $fuseLocalConcept ; /* @var $fuseForeignConcept Concept */

    public $newEntities  = null;


    private $brotherVerb  ;
    private $brotherTarget ;

    public $system  ;
    public $sc  ;


    public function setIndex($index)
    {

        if(!is_numeric($index)) {
            $unidIndex = $this->sc->get($index);
            $this->indexShortname = $index ;
        }
        else { $unidIndex = $index ;
            $this->indexShortname = $this->sc->get($index);
        }

        $this->indexUnid = $unidIndex ;

    }


    public function __construct($entityIsa,$entityContainedIn,System $system)
    {

        $this->entityIsa = $entityIsa ;
        $this->entityContainedIn = $entityContainedIn ;
        $this->factoryTable = $system->tableSuffix ;

        $this->indexUnid = $system->systemConcept->get($this->indexShortname);


        $this->system = $system ;
        $this->sc = $system->systemConcept ;


    }

    public function setSuperUser($boolean){

        $this->su = $boolean ;

    }


    public function mergeRefFromBrotherEntities($brotherVerb,  $brotherTarget){

        $this->brotherVerb = $this->sc->get($brotherVerb) ;
        $this->brotherTarget = $this->sc->get($brotherTarget) ;


    }


    /**
     * @return Entity[]
     */
    public function populateLocal($limit = 10000){

        $this->conceptManager = new ConceptManager($this->su,$this->system);

        //do we filter by isa
        if ($this->entityIsa){
            $filter = array(array('lklk' => $this->sc->get('is_a'), 'lktg' => $this->sc->get($this->entityIsa)));
            $this->conceptManager->setFilter($filter);

        }

        $entityReferenceContainer = $this->sc->get($this->entityReferenceContainer);

        //echoln("entity ref container ". $this->entityReferenceContainer);

        //echoln("container : link $entityReferenceContainer");
        //echoln("container : target $this->entityContainedIn".' should be textual '.getSC($this->entityContainedIn));

        $concepts = $this->conceptManager->getConceptsFromLinkAndTarget($entityReferenceContainer,$this->sc->get($this->entityContainedIn),$limit);
        $refs = $this->conceptManager->getReferences($entityReferenceContainer,$this->sc->get($this->entityContainedIn));

        if($this->brotherVerb or $this->brotherTarget) {
            $mergedRefs = $this->conceptManager->getReferences($this->brotherVerb , $this->brotherTarget );
        }

        //print_r($mergedRefs);


        //print_r($refs);

        $this->populated = true ;
        $this->populatedFull = true ;
        $sandraReferenceMap = array();

        //Each concept
        foreach ($refs as $key => $value){

            $concept = ConceptFactory::getConceptFromId($key);
            $entityId = $value['linkId'];
            $refArray = null ;


            //print_r($value);

            //each reference
            foreach ($value as $refConceptUnid => $refValue){

                //escape if reference is not a concept id
                if (!is_numeric($refConceptUnid))
                    continue ;

                //we are builiding the auto increment index if any
                if ($refConceptUnid == $this->indexUnid){


                    if ($refValue > $this->maxIndex){

                        $indexFound = $refValue ;
                        $this->maxIndex = $refValue ;
                    }

                }

                $refArray[$refConceptUnid] = $refValue ;

                //we add the reference in the factory reference map
                $sandraReferenceMap[$refConceptUnid] = ConceptFactory::getConceptFromId($refConceptUnid);

            }

            //there are ref to be merged
            if ($mergedRefs[$key]){

                foreach ($mergedRefs[$key] as $mergeConceptId => $refValueMerged){

                    //the reference not already exist in entity
                    if (!$refArray[$mergeConceptId]){

                        $refArray[$mergeConceptId] = $refValueMerged ;

                        //we add the reference in the factory reference map
                        $sandraReferenceMap[$refConceptUnid] = ConceptFactory::getConceptFromId($mergeConceptId);

                    }

                }

            }

            $classname = $this->generatedEntityClass ;

            $entityVerb = $this->system->conceptFactory->getConceptFromShortnameOrId($entityReferenceContainer);
            $entityTarget = $this->system->conceptFactory->getConceptFromShortnameOrId($this->entityContainedIn);

            $entity = new $classname($concept,$refArray,$this,$entityId,$entityVerb,$entityTarget,$this->system);
            $entityArray[$key] = $entity ;


            //if entity of this factory have index
            $this->entityIndexMap[$indexFound] = $entity ;


        }




        $this->addNewEtities($entityArray,$sandraReferenceMap) ;

        return $this->entityArray ;

        // print_r($refs);

    }

    public function populatePartialEntity($referenceOnVerb,$referenceOnTarget){



    }

    public function addNewEtities($entityArray, $referenceMap){

        if (!is_array($entityArray)) return ;
        if (!is_array($referenceMap)) return ;

        if ($this->entityArray){

            //  print_r($this->entityArray);
            // print_r($entityArray);

            $this->entityArray = $this->entityArray + $entityArray ;
            $this->sandraReferenceMap = $this->sandraReferenceMap + $referenceMap ;

        }
        else{

            $this->entityArray = $entityArray ;
            $this->sandraReferenceMap = $referenceMap ;
        }

    }


    public function foreignPopulate(ForeignEntityAdapter $foreignAdapter = null){


        if ($foreignAdapter == null){
            $foreignAdapter = $this->foreignAdapter ;
        }

        $foreignAdapter->populate();


        $this->addNewEtities($foreignAdapter->returnEntities(),$foreignAdapter->getReferenceMap());
        //print_r($this->entityArray);

        $this->foreignPopulated = true ;

        $this->foreignAdapter = $foreignAdapter ;


    }

    public function return2dArray(){


        //$this->verifyPopulated();
        //die(print_r($this->sandraReferenceMap));

        foreach ($this->entityArray as $key => $entity){


            foreach ($entity->entityRefs as $referenceObject){

                $refConceptUnid = $referenceObject->refConcept->idConcept ;
                $refConceptName = $referenceObject->refConcept->getDisplayName('system') ;

                //print_r($referenceObject);

                // die();

                $returnArray[$key][$refConceptName] = $referenceObject->refValue ;

            }

        }

        return $returnArray ;

    }

    public function createNewWithAutoIncrementIndex($dataArray,$linkArray=null){


        if (!$this->populatedFull){
            $this->populateLocal();

        }

        $this->maxIndex++ ;
        $dataArray[$this->indexShortname] = $this->maxIndex ;

        $this->createNew($dataArray,$linkArray);

    }

    public function setFuseForeignOnRef($foreignRef,  $localRefName){

        //$this->foreignAdapter->addToLocalVocabulary($foreignRef,$localRefName);

        $localRefConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($localRefName);
        $foreignRefConcept = $this->system->conceptFactory->getForeignConceptFromId($foreignRef);
        $this->fuseForeignConcept = $foreignRefConcept ;
        $this->fuseLocalConcept =  $localRefConcept ;


    }

    public function mergeEntities($foreignRef,  $localRefName){

        //$this->foreignAdapter->addToLocalVocabulary($foreignRef,$localRefName);

        $localRefConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($localRefName);
        $foreignRefConcept = $this->system->conceptFactory->getForeignConceptFromId($foreignRef);
        $this->fuseForeignConcept = $foreignRefConcept ;
        $this->fuseLocalConcept =  $localRefConcept ;


    }


    /**
     *
     */
    public function fuseRemoteEntity(){

        /* @var $localRefConcept $this->fuseForeignConcept */
        /* @var $localOnThisRef Entity */

        $localRefConcept = $this->fuseLocalConcept;
        $foreignRefConcept = $this->fuseForeignConcept ;

        if (!$localRefConcept)
            return ;

        //$foreignRef = $foreignRefConcept->getDisplayName() ;

        //get the map of reference that should be similar
        $localRefMap = $this->getRefMap($localRefConcept);
        //$foreignRefMap = $this->foreignAdapter->getRefMap($foreignRef);



        foreach ($localRefMap as $key => $value){

            //echoln("unsetting");

            $localOnThisRef = array();
            $foreignOnThisRef = array();

            //print_r($localRefMap);

            //do we have a foreign and a local ?
            foreach ($value as $index => $entityObj){

                $fused = false ;

                if($entityObj instanceof ForeignEntity) $foreignOnThisRef[] = $entityObj ;
                elseif ($entityObj instanceof Entity) $localOnThisRef[] = $entityObj ;
            }

            if (count($foreignOnThisRef)> 1) die ("Non unique reference on $key multiple foreign instance");
            if (count($localOnThisRef)> 1) die ("Non unique reference on $key multiple local instance");

            /* @var $foreignOnThisRef ForeignEntity */
            /* @var $localOnThisRef Entity */



            $localOnThisRef= reset($localOnThisRef);
            $foreignOnThisRef= reset($foreignOnThisRef);

            // print_r($foreignOnThisRef);



            if (is_array($localOnThisRef->entityRefs)&& is_array($foreignOnThisRef->entityRefs)){
                //echoln("fusing");
                $localOnThisRef->entityRefs =  $localOnThisRef->entityRefs + $foreignOnThisRef->entityRefs;

                $fused = true ;

            }


            // print_r($localOnThisRef->entityRefs);

            //unset($this->entityArray[$foreignOnThisRef]) ;
            // unset($foreignOnThisRef);

            $key = array_search($foreignOnThisRef, $this->entityArray);

            //if fused remove the remote entity
            if ($fused) {
                unset($this->entityArray[$key]);
            }






        }

        //unset the entitymap
        //unset($this->refMap);
        //unset($this->entityIndexMap);

        //print_r($merging);


    }

    public function createOrUpdateOnIndex($indexValue,$dataArray,$linArray=null){

        $this->verifyPopulated(true);


        //does the index exists ?
        if ($this->entityIndexMap[$indexValue]){

            print_r($this->entityIndexMap[$indexValue]);

        }

    }


    public function update($entity,$dataArray){


        //if()

    }

    public function createOrUpdateOnReference($refShortname,$refValue,$dataArray,$linkArray=null){

        $this->verifyPopulated(true);

        $referenceObject = $this->system->conceptFactory->getConceptFromId(getSC($refShortname));

        $refmap = $this->getRefMap($referenceObject);

        //Now we find if the object exists


        if($refmap[$refValue]){



        }

        //concept doens't exists
        else{

            $this->createNewWithAutoIncrementIndex($dataArray,$linkArray);

        }



    }

    //the ref map is an array that list reference as map so it's easier to acess them
    public function getRefMap($conceptObject){

        //echoln("okay we have ".count($this->entityArray)."entities");

        if (!$this->refMap[$conceptObject]){

            foreach ($this->entityArray as $value){

                //id of the reference
                //echoln("name of this specific");
                //print_r($value) ;

                $valOfConcept = $conceptObject->idConcept ;
                $valueOfThisRef = $value->entityRefs[$valOfConcept]->refValue ;


                //print_r($value->entityRefs[$valOfConcept]->refValue);
                //print_r($conceptObject);

                //echoln("$valueOfThisRef value of ref");

                //print_r($conceptObject);

                $this->refMap[$valOfConcept][$valueOfThisRef][] = $value ;
                //$value->entityRefs->$conceptObject->conceptId ;
                //print_r($value);

                //  echoln("how many interations $valueOfThisRef ?");
                // echoln("map ".count($this->refMap[$valOfConcept][$valueOfThisRef])." map");

            }

        }

        return $this->refMap[$valOfConcept] ;



    }


    public function createNew($dataArray,$linArray=null){

        global $calledByTriggerUNID, $creatorCid, $creationTimestamp, $userUNID ;

        $conceptId = createConcept("A ".$this->entityIsa,'default');



        if ($this->entityIsa){

            createLink($conceptId,getSC('is_a'), $this->sc->get($this->entityIsa));
        }

        $entityReferenceContainer = getSC($this->entityReferenceContainer);



        $link = createLink($conceptId,$entityReferenceContainer, $this->sc->get($this->entityContainedIn));

        createReference($calledByTriggerUNID, $link, $_GET['trigger']); // Trigger
        createReference($creatorCid, $link, $userUNID); // User
        createReference($creationTimestamp, $link, time()); // Creation timestamp

        //each reference
        foreach ($dataArray as $key => $value){

            if(!is_numeric($key)){

                $key =  $this->sc->get($key);
            }

            createReference($key,$link,$value);

        }

        //each link
        foreach ($linArray as $key => $value){
            createLink($conceptId, $this->sc->get($key),  $this->sc->get($value));


        }



    }

    public function verifyPopulated($fullPopulatedRequired=false){

        try {
            if (!$this->populated or !$this->populatedFull) {
                throw new Exception('Unpopulated Entity Factory');
            }
            else if ($fullPopulatedRequired && $this->populatedFull == false){
                throw new Exception('Factory not fully populated');

            }

        }
        catch(Exception $e) {
            echo 'Message: ' .$e->getMessage();
        }
    }

    public function serializeFactoryTemplate():String{

        $lightFactory = $this ;

        $lightFactory->entityArray = null ;
        $lightFactory->sandraReferenceMap = null ;
        $lightFactory->refMap = null ;
        $lightFactory->entityIndexMap = null;

        $lightFactory->populated = null ;
        $lightFactory->populatedFull = null ;
        $lightFactory->conceptManager = null ;
        $lightFactory->foreignPopulated = null ;

        return serialize($lightFactory);


    }

    public function saveEntitiesNotInLocal(){

        foreach ($this->entityArray as $key => $value){

            //it's a new entity
            if ($value instanceof ForeignEntity){
                $newEntities[] =  $value ;

                $value->save($this);

                //print_r($this);


            }


        }

        return $newEntities ;

    }

    public function getAllWith($referenceName,$referenceValue){


        if ($this->populated){


            $referenceConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($referenceName);

            $refmap = $this->getRefMap($referenceConcept);
            //print_r($refmap);

            return $refmap[$referenceValue];
        }

        die("not full populated");
        return $newEntities ;

    }

    public function getTriplets(){


        $tripletArray =  $this->conceptManager->getTriplets();

        foreach ($tripletArray as $keyConcept => $triplet){

            $concept = $this->system->conceptFactory->getConceptFromId($keyConcept);
            $concept->tripletArray =$triplet ;

            //We look at revese triplet
            foreach ($triplet as $verb => $target)
            {
                foreach ($target as $idConceptTarget) {

                    $conceptTarget = $this->system->conceptFactory->getConceptFromId($idConceptTarget);
                    $conceptTarget->reverseTriplet[$verb] = $keyConcept;

                }


            }

        }

    }







}