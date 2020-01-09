<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 15:14
 */

namespace SandraCore;
use PDO;


class System
{

    public $env = 'main' ;
    public $tableSuffix = '' ;
    public $tablePrefix = '' ;
    public $factoryManager ;

    public static $pdo ;

    public static $logger = null; /** @var DebugStack $logger */
    /** @var  DebugStack $debugStack DebugStack instance used to log sandra requests. */
    private $debugStack;
    public $systemConcept; // public for now, should be made private

    private $testMode;

    // Assumptions
    public  $deletedUNID ;

    public $conceptTable;
    public $linkTable;
    public $tableReference;
    public $tableStorage;
    public $tableConf ;
    public $conceptFactory ;
    public $foreignConceptFactory ;
    public $errorLevelToKill = 3 ;
    public $registerStructure = false;
    public $registerFactory = array();
    public $instanceId ;
    private $entityClassStore;

    public  function __construct($env = '', $install = false, $dbHost='127.0.0.1', $db='sandra', $dbUsername='root', $dbpassword=''){

        self::initDebugStack();
        if (!self::$pdo)
            self::$pdo = new PdoConnexionWrapper($dbHost, $db,$dbUsername, $dbpassword);

        $pdoWrapper = self::$pdo ;
        // $this->pdo =

        $prefix = $env ;
        $this->tablePrefix = $prefix ;
        $suffix = '';
        $this->env = $env;

        $this->conceptTable = $prefix .'_SandraConcept' . $suffix;
        $this->linkTable =  $prefix .'_SandraTriplets' . $suffix;
        $this->tableReference =  $prefix .'_SandraReferences' . $suffix;
        $this->tableStorage =  $prefix .'_SandraDatastorage' . $suffix;
        $this->tableConf =  $prefix .'_SandraConfig' . $suffix;

        if ($install) $this->install();


        $this->systemConcept = new SystemConcept($pdoWrapper, self::$logger, $this->conceptTable);

        $this->deletedUNID = $this->systemConcept->get('deleted');
        //die("on system deleted ".$this->deletedUNID);

        self::$logger->connectionInfo = array('Host' => $pdoWrapper->host, 'Database' => $pdoWrapper->database, 'Sandra environment' => $env);

        $this->factoryManager = new FactoryManager($this);
        $this->conceptFactory = new ConceptFactory($this);

        $this->instanceId = rand(0,999)."-".rand(0,9999)."-".rand(0,999);


        //$this->logger->info('[Sandra] Started sandra ' . $env . ' environment successfully.');

    }

    public function initDebugStack(){

        if (!self::$logger) {
            $debugStack = new DebugStack();
            //disable logger by default
            $debugStack->enabled = false ;
            self::$logger = $debugStack;
        }


    }

    public function install(){

        SandraDatabaseDefinition::createEnvTables($this->conceptTable,$this->linkTable,$this->tableReference,$this->tableStorage,$this->tableConf);


    }

    public function registerFactory(EntityFactory $factory)
    {

        if ($this->registerStructure) {

            $this->registerFactory[get_class($factory)] = $factory;
        }


    }

    public static function logDatabaseStart($query,$params=null,$types=null){

        self::$logger->startQuery($query,$params,$types);


    }

    public static function logDatabaseEnd($error=null){

        self::$logger->stopQuery();


    }


    public static function sandraException(\Exception $exception){

        //print_r($exception);
        switch ($exception->getCode()){

            case '42S02' :
                echo"unavailable database";

                break;


        }
        print_r($exception->getMessage());

        //print_r($exception);


        throw new $exception;


    }

    public function systemError($code, $source, $level, $message){

        //Level 1 Simple notice
        //Level 2 Caution
        //Level 3 Important
        //Level 4 Critical

        self::$logger->registerMessage("Error : $code From $source : ".$message);

        if (isset($level)&&$level>=$this->errorLevelToKill) {

            die("Error : $code From $source : ".$message);
        }

        return $message ;

    }

    public function killingProcessLevel($code, $source, $level, $message){


        die($message);

    }

    public function entityToClassStore($className, EntityFactory $factory)
    {


        if (!isset($this->entityClassStore[$className])) {


            $factory->populateLocal();
            $this->entityClassStore[$className] = $factory->getOrCreateFromRef('class_name', $className);


        }
        return $this->entityClassStore[$className];


    }

    public function destroy() {


        $this->factoryManager->destroy();
        $this->conceptFactory->destroy();
        $this->conceptFactory->system = null ;
        $this->registerStructure = null ;


    }


}