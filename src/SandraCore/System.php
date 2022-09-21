<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 15:14
 */

namespace SandraCore;


use Opcodes\LogViewer\Log;

class System
{

    public static $pdo;
    public static ILogger $sandraLogger;

    public $env = 'main';
    public $tableSuffix = '';
    public $tablePrefix = '';

    public $factoryManager;
    public $systemConcept;
    public $conceptFactory;

    //TODO Public for now, should be made private
    public $deletedUNID;

    // Assumptions
    public $conceptTable;
    public $linkTable;
    public $tableReference;
    public $tableStorage;
    public $tableConf;
    public $foreignConceptFactory;

    public $errorLevelToKill = 3;
    public $registerStructure = false;
    public $registerFactory = array();
    public $instanceId;

    private $entityClassStore;

    public function __construct($env = '', $install = false, $dbHost = '127.0.0.1', $db = 'sandra', $dbUsername = 'root', $dbpassword = '', ?ILogger $logger = null)
    {

        self::$sandraLogger = new Logger();

        if (!static::$pdo)
            static::$pdo = new PdoConnexionWrapper($dbHost, $db, $dbUsername, $dbpassword);

        $pdoWrapper = static::$pdo;

        $prefix = $env;
        $this->tablePrefix = $prefix;
        $suffix = '';
        $this->env = $env;

        $this->conceptTable = $prefix . '_SandraConcept' . $suffix;
        $this->linkTable = $prefix . '_SandraTriplets' . $suffix;
        $this->tableReference = $prefix . '_SandraReferences' . $suffix;
        $this->tableStorage = $prefix . '_SandraDatastorage' . $suffix;
        $this->tableConf = $prefix . '_SandraConfig' . $suffix;

        if ($install) $this->install();

        $this->systemConcept = new SystemConcept($pdoWrapper, null, $this->conceptTable);
        $this->deletedUNID = $this->systemConcept->get('deleted');
        $this->factoryManager = new FactoryManager($this);
        $this->conceptFactory = new ConceptFactory($this);
        $this->instanceId = rand(0, 999) . "-" . rand(0, 9999) . "-" . rand(0, 999);

        if ($logger)
            self::$sandraLogger = $logger;

    }

    public function initDebugStack()
    {
        // Function removed.
    }

    public function install()
    {
        SandraDatabaseDefinition::createEnvTables($this->conceptTable, $this->linkTable, $this->tableReference, $this->tableStorage, $this->tableConf);
    }


    /** @noinspection PhpUnhandledExceptionInspection */
    public static function sandraException(\Exception $exception)
    {

        // Pass exception to log
        self::$sandraLogger->error($exception);

        //print_r($exception);
        switch ($exception->getCode()) {
            case '42S02' :
                echo "unavailable database";
                break;
        }

        print_r($exception->getMessage());

        throw $exception;

    }

    public function registerFactory(EntityFactory $factory)
    {
        if ($this->registerStructure) {
            $this->registerFactory[get_class($factory)] = $factory;
        }
    }

    public function systemError($code, $source, $level, $message)
    {
        //Level 1 Simple notice
        //Level 2 Caution
        //Level 3 Important
        //Level 4 Critical

        if (isset($level) && $level >= $this->errorLevelToKill) {
            die("Error : $code From $source : " . $message);
        }

        return $message;

    }

    public function killingProcessLevel($code, $source, $level, $message)
    {
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

    public function destroy()
    {
        $this->factoryManager->destroy();
        $this->conceptFactory->destroy();
        $this->conceptFactory->system = null;
        $this->registerStructure = null;
    }

}
