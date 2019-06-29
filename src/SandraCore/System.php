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

    private $logger;
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

    public  function __construct($env = '',$install = false,$dbHost='127.0.0.1',$db='sandra',$dbUsername='root',$dbpassword=''){

        self::$pdo = new PdoConnexionWrapper($dbHost, $db,$dbUsername, $dbpassword);
        $pdoWrapper = self::$pdo ;

        $prefix = $env ;
        $this->tablePrefix = $prefix ;
        $suffix = '';

        $this->conceptTable = $prefix .'_SandraConcept' . $suffix;
        $this->linkTable =  $prefix .'_SandraTriplets' . $suffix;
        $this->tableReference =  $prefix .'_SandraReferences' . $suffix;
        $this->tableStorage =  $prefix .'_SandraDatastorage' . $suffix;
        $this->tableConf =  $prefix .'_SandraConfig' . $suffix;

        if ($install) $this->install();

        $debugStack = new DebugStack();
        $this->logger = $debugStack;

        $this->systemConcept = new SystemConcept($pdoWrapper, $this->logger, $this->conceptTable);

        $this->deletedUNID = $this->systemConcept->get('deleted');
        //die("on system deleted ".$this->deletedUNID);

        $debugStack->connectionInfo = array('Host' => $pdoWrapper->host, 'Database' => $pdoWrapper->database, 'Sandra environment' => $env);

        $this->factoryManager = new FactoryManager($this);
        $this->conceptFactory = new ConceptFactory($this);



        //$this->logger->info('[Sandra] Started sandra ' . $env . ' environment successfully.');

    }

    public function install(){

        SandraDatabaseDefinition::createEnvTables($this->conceptTable,$this->linkTable,$this->tableReference,$this->tableStorage,$this->tableConf);



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



    die();


    }

    public function systemError($code,$source,$level,$message){
        
        

        die($message);

    }


}