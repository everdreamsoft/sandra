<?php

/*
	2016.11.08 Cache system has been removed to avoid collision between table switch
*/

namespace SandraCore;

use PDOException ;
use PDO;

class SystemConcept
{
    const DIRNAME = 'dependencies/cache/';
    const FILENAME = 'dependencies/cache/system_concepts_%s.json';

    private $pdo;

    private  $_conceptsByTable = array();
    private  $_conceptsByTableUnsensitive = array();

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

            System::sandraException($exception);
            return ;
        }




        $concepts = array();
        foreach($result->fetchAll(PDO::FETCH_OBJ) as $row)
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


            $key = array_search($concept_id, $this->_conceptsByTable[$table]);

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


        $sql = "SELECT * FROM $this->conceptTable WHERE `code` LIKE '" . $code . "'";

        //$result = mysqli_query ($dbLink,$sql); //action;;

        try {
            $result = $this->pdo->query($sql);
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }

        if($result->rowCount() > 0) {
            return $result->fetchAll(PDO::FETCH_OBJ);
        }

        return null;
    }

    //Find  specific concept from cache file or db
    public  function get($shortname, $table = null, $forceCreate = true)
    {
        $table = $this->assignDefaultTable($table);

        //we have to lowecase the shortname
        $shortnameCaseUnsensitive = strtolower($shortname);

        //if an id is provided already
        if (is_numeric($shortname)) return $shortname ;

        if(!isset($this->_conceptsByTable[$table]))
            try {

                $this->load($table);
            }catch(PDOException $exception) {
                System::sandraException($exception);

            }


        if(!isset($this->_conceptsByTableUnsensitive[$table][$shortnameCaseUnsensitive]))
        {
            $concept = $this->getFromDB($shortname, $table);


            if($concept != null)
            {
                //self::write($table);	//reload all things from db and update json file
                $this->_conceptsByTable[$table][$shortname] = $concept->id;
                $this->_conceptsByTableUnsensitive[$table][$shortnameCaseUnsensitive] = $concept->id;
                return $concept->id;
            }
            else
            {
                if($forceCreate)
                {
                    $id = self::create($shortname, $table);
                    $this->_conceptsByTable[$table][$shortname] = $id;
                    $this->_conceptsByTableUnsensitive[$table][$shortnameCaseUnsensitive] = $id;
                    return $id;
                }
                return null;
            }

        }
        else{
            //echo"loading $shortname \n";
            return $this->_conceptsByTableUnsensitive[$table][$shortnameCaseUnsensitive];
        }

    }

    private  function getFromDBWithId($concept_id, $table = null)
    {


        $table = $this->assignDefaultTable($table);

        if(!is_numeric($concept_id))
            throw new Exception("Bad request: concept_id must be numeric");

        $sql = "SELECT id,shortname FROM $this->conceptTable WHERE id = $concept_id;";




        try {
            $result = $this->pdo->query($sql);
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }


        if($result->rowCount() > 0) {

            return $result->fetchAll(PDO::FETCH_OBJ);

        }


        return null;
    }

    private  function getFromDBWithIdIncludingCode($concept_id, $table = null)
    {


        $table = $this->assignDefaultTable($table);

        if(!is_numeric($concept_id))
            throw new Exception("Bad request: concept_id must be numeric");

        $sql = "SELECT id,code,shortname FROM $this->conceptTable WHERE id = $concept_id;";


        try {
            $result = $this->pdo->query($sql);
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }


        if($result->rowCount() > 0) {

            return $result->fetchAll(PDO::FETCH_OBJ);

        }


        return null;
    }

    //Get one system concept from DB
    private  function getFromDB($shortname, $table = null)
    {


        $table = $this->assignDefaultTable($table);




        $sql = "SELECT id,shortname FROM $this->conceptTable WHERE shortname = '$shortname'";


        try {
            $result = $this->pdo->prepare($sql);
            $result->execute();
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }






        if($result->rowCount() > 0)
            return $result->fetchAll(PDO::FETCH_OBJ);

        return null;
    }

    private  function create ($shortname, $table = null)
    {


        $table = $this->assignDefaultTable($table);



        $sql = "INSERT INTO $this->conceptTable (id, code, shortname) VALUES (null, 'system concept $shortname', '$shortname');";

        try {

            $result = $this->pdo->prepare($sql);
            $result->execute();
        }
        catch(PDOException $exception){

            System::sandraException($exception);
            return ;
        }


        return 	$this->pdo->lastInsertId() ;
    }

    //Find existings shortname in unidList and update database table
    public  function migrateShortname($table = null, $unid = null)
    {


        $table = $this->assignDefaultTable($table);

        $list = is_null($unid) || !is_array($unid) ? $unidList : $unid;

        foreach($list as $shortname => $id)
        {
            $sql = "UPDATE $this->conceptTable SET shortname = '$shortname' WHERE id = $id";
            mysqli_query ($dbLink, $sql);

        }
    }

    //Load concepts from cache file
    public  function load($table = null)
    {
        $table = $this->assignDefaultTable($table);

        $this->_conceptsByTable[$table] = $this->listAll($table);

                //build the case insensitive array
                foreach  ($this->_conceptsByTable[$table] as $key => $value){
                    $key = strtolower($key);
                    $this->_conceptsByTableUnsensitive[$table][$key] = $value ;
                }

/*
                 Never write the cache
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


        if(is_null($table))
            $table = $this->conceptTable;

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

    public function tryGetSC($shortname, $table = null)
    {
        return SystemConcept::get($shortname, $table, false);
    }

    function getSCS($concept_id, $table = null)
    {
        return $this->getShortname($concept_id, $table);
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