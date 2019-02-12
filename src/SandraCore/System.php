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
    public $factoryManager ;

    public static $pdo ;

    private $logger;
    /** @var  DebugStack $debugStack DebugStack instance used to log sandra requests. */
    private $debugStack;
    public $systemConcept; // public for now, should be made private

    private $testMode;

    // Assumptions
    private $deletedUNID ;

    public $conceptTable;
    public $linkTable;
    public $tableReference;
    public $tableStorage;
    public $tableConf ;

    public  function __construct($env = '',$install = false){

        self::$pdo = new PdoConnexionWrapper('localhost', 'sandra','root', '');
        $pdoWrapper = self::$pdo ;

        $suffix = $env ;
        $this->tableSuffix = $suffix ;

        $this->conceptTable = 'SandraConcept' . $suffix;
        $this->linkTable = 'SandraTriplets' . $suffix;
        $this->tableReference = 'SandraReferences' . $suffix;
        $this->tableStorage = 'SandraDatastorage' . $suffix;
        $this->tableConf = 'SandraConfig' . $suffix;

        if ($install) $this->install();

        $debugStack = new DebugStack();
        $this->logger = $debugStack;

        $this->systemConcept = new SystemConcept($pdoWrapper, $this->logger, $this->conceptTable);

        $this->deletedUNID = $this->systemConcept->get('deleted');

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



    die();




    }


}