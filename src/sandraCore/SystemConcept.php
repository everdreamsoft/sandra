<?php

/*
	2016.11.08 Cache system has been removed to avoid collision between table switch
*/

namespace SandraDG;

use PDOException ;

class SystemConcept
{
    const DIRNAME = 'dependencies/cache/';
    const FILENAME = 'dependencies/cache/system_concepts_%s.json';

    private $pdo;

    private static $_conceptsByTable = array();

    private static $_tableLoaded = 'default';

    private static $_loadedTables = array();

    private static $_includePath = '';
    private  $conceptTable = '';

    private  $_instance;


    public function __construct(PdoConnexionWrapper $pdoConnexionWrapper,  $logger, $conceptTable)
    {
        $this->_instance = $this;

        $this->pdo = $pdoConnexionWrapper->get();
        $this->logger = $logger;
        $this->conceptTable = $conceptTable;



        if(defined('SANDRA_INCLUDE_PATH'))
            self::$_includePath = SANDRA_INCLUDE_PATH;
    }

    public  function getInstance()
    {
        if(null === self::$_instance)
        {
            $this->_instance = new SystemConcept();
        }

        return $this->_instance;
    }

    //List every system concept from table
    private  function listAll($table = null)
    {

        $table = self::assignDefaultTable($table);

        $sql = "SELECT id, shortname FROM $this->conceptTable WHERE shortname != '' ";

        try {
            $result = $this->pdo->query($sql);
        }
        catch(PDOException $exception){
            print_r($exception);
            die($exception->getMessage());
            return $exception->getMessage();
        }


        $concepts = array();
        while($row = mysqli_fetch_object($result))
        {
            $concepts[$row->shortname] = $row->id;
        }
        return $concepts;
    }

    //Reversed function
    public  function getShortname($concept_id, $table = null)
    {

        $table = $this->assignDefaultTable($table);


        //print_r(self::$_conceptsByTable[$table]);

        if(isset($this->_conceptsByTable[$table]))
        {


            $key = array_search($concept_id, self::$_conceptsByTable[$table]);

            if($key) {

                return $key;

            }

            else {
                return null ;

            }
        }

        $concept = $this->getFromDBWithId($concept_id, $table);


        if($concept != null)
        {
            self::write($table);
            return $concept->shortname;
        }
    }

    public  function getCode($concept_id, $table = null)
    {
        $concept = $this->getFromDBWithIdIncludingCode($concept_id, $table);

        if($concept != null)
        {
            self::write($table);
            return $concept->code;
        }

        return null;
    }

    public  function getConceptIdFromCode($code, $table = null)
    {
        $concept = $this->getFromDBWithCode($code, $table);

        return $concept->id;
    }

    private  function getFromDBWithCode($code, $table = null)
    {
        global $tableLink, $tableReference, $tableConcept, $dbLink;

        $sql = "SELECT * FROM $tableConcept WHERE `code` LIKE '" . $code . "'";

        $result = mysqli_query ($dbLink,$sql); //action;;

        if(mysqli_num_rows($result) > 0) {
            return mysqli_fetch_object($result);
        }

        return null;
    }

    //Find  specific concept from cache file or db
    public  function get($shortname, $table = null, $forceCreate = true)
    {
        $table = $this->assignDefaultTable($table);

        //if an id is provided already
        if (is_numeric($shortname)) return $shortname ;

        if(!isset(self::$_conceptsByTable[$table]))
            $this->load($table);

        if(!isset(self::$_conceptsByTable[$table][$shortname]))
        {
            $concept = self::getFromDB($shortname, $table);

            if($concept != null)
            {
                //self::write($table);	//reload all things from db and update json file
                $this->_conceptsByTable[$table][$shortname] = $concept->id;
                return $concept->id;
            }
            else
            {
                if($forceCreate)
                {
                    $id = self::create($shortname, $table);
                    $this->_conceptsByTable[$table][$shortname] = $id;
                    return $id;
                }
                return null;
            }

        }
        else{
            return $this->_conceptsByTable[$table][$shortname];
        }

    }

    private  function getFromDBWithId($concept_id, $table = null)
    {
        global $tableLink,  $tableReference, $tableConcept, $dbLink;

        $table = $this->assignDefaultTable($table);

        if(!is_numeric($concept_id))
            throw new Exception("Bad request: concept_id must be numeric");

        $sql = "SELECT id,shortname FROM $tableConcept WHERE id = $concept_id;";


        $result = mysqli_query ($dbLink,$sql); //action;;


        if(mysqli_num_rows($result) > 0) {

            return mysqli_fetch_object($result);

        }


        return null;
    }

    private  function getFromDBWithIdIncludingCode($concept_id, $table = null)
    {
        global $tableLink,  $tableReference, $tableConcept, $dbLink;

        $table = $this->assignDefaultTable($table);

        if(!is_numeric($concept_id))
            throw new Exception("Bad request: concept_id must be numeric");

        $sql = "SELECT id,code,shortname FROM $tableConcept WHERE id = $concept_id;";


        $result = mysqli_query ($dbLink,$sql); //action;;


        if(mysqli_num_rows($result) > 0) {

            return mysqli_fetch_object($result);

        }


        return null;
    }

    //Get one system concept from DB
    private  function getFromDB($shortname, $table = null)
    {
        global $tableLink, $tableReference, $tableConcept, $dbLink;

        $table = $this->assignDefaultTable($table);

        $shortname = mysqli_escape_string($dbLink,$shortname);

        $sql = "SELECT id,shortname FROM $tableConcept WHERE shortname = '$shortname';";


        $result = mysqli_query ($dbLink, $sql); //action;;

        if(mysqli_num_rows($result) > 0)
            return mysqli_fetch_object($result);

        return null;
    }

    private  function create ($shortname, $table = null)
    {
        global $tableLink,  $tableReference, $tableConcept, $dbLink;

        $table = $this->assignDefaultTable($table);

        $code = mysqli_escape_string($dbLink, $shortname);
        $shortname = mysqli_escape_string($dbLink, $shortname);

        $sql = "INSERT INTO $tableConcept (id, code, shortname) VALUES (null, '$code', '$shortname');";
        $resultat = mysqli_query ($dbLink, $sql); //action;;



        return 	mysqli_insert_id($dbLink) ;
    }

    //Find existings shortname in unidList and update database table
    public  function migrateShortname($table = null, $unid = null)
    {
        global $tableLink,  $tableReference, $tableConcept, $unidList, $dbLink;

        $table = $this->assignDefaultTable($table);

        $list = is_null($unid) || !is_array($unid) ? $unidList : $unid;

        foreach($list as $shortname => $id)
        {
            $sql = "UPDATE $tableConcept SET shortname = '$shortname' WHERE id = $id";
            mysqli_query ($dbLink, $sql);

        }
    }

    //Load concepts from cache file
    public  function load($table = null)
    {
        $table = self::assignDefaultTable($table);

        $this->_conceptsByTable[$table] = $this->listAll($table);
        /* Never write the cache
        self::tryCreateFile($table);
        $content = file_get_contents(sprintf(self::$_includePath . self::FILENAME, $table));
        $json = json_decode($content);
        self::$_concepts = array();
        if((bool)empty($json))
            return array();

        foreach($json as $k=>$v)
            self::$_concepts[$k] = $v;

        self::$_tableLoaded = $table;
        */
    }

    private  function assignDefaultTable($table = null)
    {
        global $tableConcept;

        if(is_null($table))
            $table = $tableConcept;

        return $table;
    }



    //Save concepts from cache file
    public  function write($table = null)
    {
        $table = $this->assignDefaultTable($table);

        self::tryCreateFile($table);
        $filename = sprintf(self::$_includePath . self::FILENAME, $table);
        $concepts = $this->listAll();
        file_put_contents($filename, json_encode($concepts));
    }

    public  function tryCreateFile($table = null)
    {
        $table = $this->assignDefaultTable($table);

        if(!file_exists(self::$_includePath . self::DIRNAME))
            mkdir(self::$_includePath . self::DIRNAME);

        $filename = sprintf(self::$_includePath . self::FILENAME, $table);
        if(!file_exists($filename))
            touch($filename);
    }
}

/*
//Get system concept, if not exists create it and write list to cache
function getSC($shortname, $table = null)
{
    return SystemConcept::get($shortname, $table);
}

function getCode($concept_id, $table = null)
{
    return SystemConcept::getCode($concept_id, $table);
}

function getConceptFromCode($code, $table = null) {
    return SystemConcept::getConceptIdFromCode($code);
}

function getSCS($concept_id, $table = null)
{
    return SystemConcept::getShortname($concept_id, $tableConcept);
}

function getSCSCheck($concept,$table = null)
{
    if (is_numeric($concept)) {
        return getSCS($concept,$tableConcept);
    } elseif (is_string ($concept) && tryGetSC ($concept,$tableConcept)) {
        return $concept ;
    } else{
        return 0;
    }
}

function tryGetSC($shortname, $table = null)
{
    return SystemConcept::get($shortname, $tableConcept, false);
}
*/