<?php
namespace SandraCore;
use SandraCore\displayer\Displayer;
use SandraCore\displayer\DisplayType;

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 09:29
 */



abstract class FactoryBase
{
    /* @var $conceptManager ConceptManager */
    private $defaultFactoryName = 'noNameFactory';
    public $conceptManager ;

    public $displayer ;
    public $tripletfilter ;
    public $system ;
    private $su = true; //is the factory super user status

    /* @var $entityArray Entity[] */
    public $entityArray = array();

    public $entityIsa;
    public $entityContainedIn;

    public $indexShortname = 'index';

    public $factoryIdentifier = '' ;

    abstract public function getAllWith($referenceName, $referenceValue);
    abstract public function createNew($dataArray, $linkArray = null);
    abstract public function populateLocal($limit = 10000,$offset = 0,$asc='ASC');

    public $populated ;

    protected $generatedEntityClass = '\SandraCore\Entity';

    public function __construct(System $system)
    {
        $this->system = $system ;
        $this->factoryIdentifier = $this->defaultFactoryName ;
        $system->registerFactory($this);
        $system->factoryManager->register($this);

        $this->conceptManager = new ConceptManager($this->su, $this->system);

    }

    /**
     * @return Entity[]
     */
    public function getEntities(){

        return $this->entityArray ;

    }

    public function first($referenceName, $referenceValue) : ?Entity{

       $resultArray = $this->getAllWith($referenceName, $referenceValue) ;
       if (!is_array($resultArray)) return null ;

       $result = reset($resultArray);

        return $result;

    }

    public function last($referenceName, $referenceValue) : ?Entity{

        $resultArray = $this->getAllWith($referenceName, $referenceValue) ;
        if (!is_array($resultArray)) return null ;

        $result = end($resultArray);

        return $result;

    }
    

    public function getDisplay($format,$refToDisplay = null, $dictionnary=null, DisplayType $displayType = null){

        $displayer = $this->initDisplayer($displayType);

        if($displayType) {
            $displayer->displayType = $displayType;
            $displayType->bindToDisplayer($displayer);
        }

       
        $this->displayer = $displayer ;

        if (!is_null($dictionnary)) $displayer->setDisplayDictionary($dictionnary);
        if (!is_null($refToDisplay)) $displayer->setDisplayRefs($refToDisplay);

        return  $displayer->getAllDisplayable();
        

    }

    public function getOrCreateFromRef($refname,$refvalue):Entity{

       $entity = $this->first($refname,$refvalue);
       if(!$entity) {
        $entity =   $this->createNew(array($refname=>$refvalue));
               }

        return $entity ;
    }

    public function setFilter($verb=0,$target=0,$exclusion=false):EntityFactory{

        $verbArray = array();
        $targetArray = array();

        if (is_array($verb)) {
            foreach ($verb as $currentVerb) {
                $verbConceptId = CommonFunctions::somethingToConceptId($currentVerb, $this->system);
                $verbArray[] = $verbConceptId;

            }
        } else {
            $verbArray[] = CommonFunctions::somethingToConceptId($verb, $this->system);
        }

        if (is_array($target)) {
            foreach ($target as $currentTarget) {
                $targetConceptId = CommonFunctions::somethingToConceptId($currentTarget, $this->system);
                $targetArray[] = $targetConceptId;

            }
        } else {
            $targetArray[] = CommonFunctions::somethingToConceptId($target, $this->system);
        }

        $implodeVerbs = implode(",", $verbArray);
        $implodeTargets = implode(",", $targetArray);


        $this->tripletfilter[implode(",", $verbArray) . implode(",", $targetArray)]['verbConceptId'] = $implodeVerbs;
        $this->tripletfilter[implode(",", $verbArray) . implode(",", $targetArray)]['targetConceptId'] = $implodeTargets;
        $this->tripletfilter[implode(",", $verbArray) . implode(",", $targetArray)]['exclusion'] = $exclusion;

        return $this ;

    }


    protected function initDisplayer(DisplayType $displayType = null){

        if (!isset($this->displayer)){

            $this->displayer = new Displayer($this,$displayType);


        }

        return $this->displayer ;
        

    }

    public function setGeneratedClass($class){

        if (class_exists($class)) {
            $this->generatedEntityClass = $class ;
            return ;
        }

        $this->system->systemError('546',self::class,'1',"created class does not exists $class");





    }



    public function createViewTable($name):FactoryBase{


        if (!$this->populated){
            $this->populateLocal(1000,0,'DESC');

        }
        $sandra = $this->system;

        $entityReferenceContainer = $sandra->systemConcept->get($this->entityReferenceContainer);
        $containedUnid = $sandra->systemConcept->get($this->entityContainedIn);
        $creationTimestamp = $sandra->systemConcept->get("creationTimestamp");

        $incrementor = 0 ;

        $fieldList = "";
        $SQLviewFilterJoin = "";
        $SQLviewFilterCondition =  "rf.idConcept = rf.idConcept AND " ; //some default data in case to fill out the blannk
        $additionalFilterCount = 0;
        $filters = "";

        foreach ($this->sandraReferenceMap as $concept){
            /** @var  Concept $concept */

            //we bypass creationTimestamp as it is by default
            if ($concept->idConcept == $sandra->systemConcept->get('creationTimestamp')) continue ;

            $incrementor++ ;
            $concept->idConcept;
            
            /** @var System $sandra */
            $fieldname = $concept->getDisplayName();


            /** @var ConceptManager $conceptManager */

            $fieldList .= ",\n rf$incrementor".".value '$fieldname' ";
            $SQLviewFilterJoin .= " LEFT JOIN  `$sandra->tableReference` rf$incrementor ON l.id = rf$incrementor.`linkReferenced` AND rf$incrementor.idConcept = $concept->idConcept \n";



        }

        //if is_a is specified we add the filter
        if (!is_null($this->entityIsa)) {
            $additionalFilterCount++;
            $isaUnid = $sandra->systemConcept->get("is_a");
            $isaTargetUnid = $sandra->systemConcept->get($this->entityIsa);
            $filters .= "\nJOIN  $sandra->linkTable lf$additionalFilterCount ON l.idConceptStart = lf$additionalFilterCount.idConceptStart AND lf$additionalFilterCount.idConceptLink = $isaUnid AND lf$additionalFilterCount.idConceptTarget = $isaTargetUnid \n ";
        }


        $sql = " CREATE OR REPLACE VIEW `" . $sandra->tablePrefix . "_view_$name` AS SELECT l.idConceptStart unid $fieldList ,
                    FROM_UNIXTIME(rf.value) updated FROM $sandra->linkTable l LEFT JOIN    `$sandra->tableReference` rf ON l.id = rf.`linkReferenced` AND rf.idConcept = $creationTimestamp
                     \n $SQLviewFilterJoin $filters
                     WHERE $SQLviewFilterCondition l.idConceptLink = $entityReferenceContainer AND l.idConceptTarget = $containedUnid AND l.flag != $sandra->deletedUNID";
        DatabaseAdapter::executeSQL($sql);
        return $this;




    }


    public function createPortableFactory():EntityFactory{

        //the idea of portable factory is to be able to persist it in the datagraph

        $portableFactory = new EntityFactory($this->entityIsa,$this->entityContainedIn,$this->system);

        $portableFactory->indexShortname = $this->indexShortname ;


        //if factory has no name we call it factory-isa-file-className

        $lightJoinedFactory = array();


        foreach ($this->joinedFactoryArray as $verb => $factory)
        {
            /** @var EntityFactory $factory */

            $lightJoinedFactory[$verb] = $factory->createPortableFactory();

        }

        $portableFactory->joinedFactoryArray = $lightJoinedFactory ;


        return $portableFactory ;


    }

    public function setSuperUser($boolean)
    {

        $this->su = $boolean;
        $this->conceptManager = new ConceptManager($this->su, $this->system);

    }

    public function destroy(){

       unset($this->system);
       $this->conceptManager->destroy();
       unset($this->conceptManager);

        /** @var Displayer $displayer */
        $displayer = $this->displayer;
        $displayer->mainFactory = null ;

        foreach ($displayer->factoryArray as $key=> $factory){

            if ($factory == $this) $displayer->factoryArray[$key] = null ;
        }
        $displayer->displayType->destroy();

    }

   


    


}