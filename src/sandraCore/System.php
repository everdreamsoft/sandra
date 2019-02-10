<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 15:14
 */

namespace SandraDG;
use PDO;



class System
{

    public $env = 'main' ;
    public static $pdo ;

    private $logger;
    /** @var  DebugStack $debugStack DebugStack instance used to log sandra requests. */
    private $debugStack;
    public $systemConcept; // public for now, should be made private

    private $testMode;

    // Assumptions
    private $deletedUNID = 1107; //Todo delete this

    public $conceptTable;
    public $linkTable;
    public $tableReference;
    public $tableStorage;

    public  function __construct($env = ''){

        self::$pdo = new PdoConnexionWrapper('localhost', 'sandra','root', '');
        $pdoWrapper = self::$pdo ;

       $suffix = $env ;

        $this->conceptTable = 'ConceptSD' . $suffix;
        $this->linkTable = 'TripletSD' . $suffix;
        $this->tableReference = 'ReferencesSD' . $suffix;
        $this->tableStorage = 'DataStorageSD' . $suffix;

        $debugStack = new DebugStack();
        $this->logger = $debugStack;

        $this->systemConcept = new SystemConcept($pdoWrapper, $this->logger, $this->conceptTable);

        $debugStack->connectionInfo = array('Host' => $pdoWrapper->host, 'Database' => $pdoWrapper->database, 'Sandra environment' => $env);
        //$this->logger->info('[Sandra] Started sandra ' . $env . ' environment successfully.');


    }

    public static function install(){






    }


}