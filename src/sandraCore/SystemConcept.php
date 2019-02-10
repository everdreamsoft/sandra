<?php
namespace SandraDG;

use Exception;
//use Symfony\Bridge\Monolog\Logger;

class SystemConcept
{
	private $pdo;
	
	/**
	 * @var Logger $logger Monolog logger
	 */
	private $logger;
	
	private $conceptTable;
	
	private $concepts;

	public function __construct(PdoConnexionWrapper $pdoConnexionWrapper, DebugStack $logger, $conceptTable)
	{
		$this->pdo = $pdoConnexionWrapper->get();
		$this->logger = $logger;
		$this->conceptTable = $conceptTable; 
	}
	
	/**
	 * Gets a concept id from a shortname.
	 *
	 * @param string $shotname The shortname of the systemconcept we're looking for.
	 * @param bool $allowCreate If true, a shortname will be automatically created if it doesn't already exists.
	 *
	 * @return int The id of the system concept.
	 */
	public function get($shortname, $allowCreate = true)
	{
		if ($shortname === null || $shortname === '')
			throw new \InvalidArgumentException('$shortname can\'t be null or empty');
			
		if (isset($this->concepts[$shortname])) {
			// We already know this shortname's concept id
			return (int) $this->concepts[$shortname];
		}
		
		// retrive the concept id from the db 
		$id = $this->getFromDB($shortname, $this->conceptTable);
		
		if ($id === null) {
			if (!$allowCreate) {
				return null;
			}
			$id = $this->create($shortname, $this->conceptTable);
		}
		
		$this->concepts[$shortname] = $id;
		return (int) $id;
	}
	
	//Reversed function
	// public function getShortname($conceptId)
	// {
	// 	foreach($this->concepts as $k => $v)
	// 		if($v == $conceptId)
	// 			return $k;
	// 
	// 	$concept = $this->getFromDBWithId($conceptId);
	// 
	// 	if($concept != null)
	// 	{
	// 		//self::write($table);
	// 		return $concept->shortname;
	// 	}
	// }
	
	// private function getFromDBWithId($conceptId)
	// {
	// 	$tableConcept = $this->database->tableConcept;
	// 
	// 	if(!is_numeric($conceptId))
	// 		throw new \Exception("bad request conceptId must be numeric");
	// 
	// 	$sql = "SELECT id,shortname FROM $tableConcept WHERE id = $conceptId;";
	// 	
	// 	$result = $this->sqlQuery($sql);
	// 
	// 	if($result->rowCount() > 0)
	// 		return $result->fetchObject();
	// 	
	// 	return null;
	// }

	//Get one system concept from DB
	private function getFromDB($shortname)
	{
		// TODO: use prepare execute
		$sql = "SELECT id,shortname FROM $this->conceptTable WHERE shortname = '$shortname';";
		
		$result = $this->sqlQuery($sql);
		
		if($result->rowCount() > 0)
			return $result->fetchObject()->id;
		
		return null;
	}

	private function create ($shortname)
	{
		$this->checkLockWrite();
		
		// TODO: use prepare execute
		$code = $shortname;

		$sql = "INSERT INTO $this->conceptTable (id, code, shortname) VALUES ('', '$code', '$shortname');" ;
		$resultat = $this->sqlQuery($sql);

		$id = $this->pdo->lastInsertId();

		$this->logger->info("[Sandra] Created system concept \"$shortname\" ($id)");

		return $id;
	}

	//Find existings shortname in unidList and update database table
	// public static function migrateShortname($unid = null)
	// {
	// 	global $tableLink,  $tableReference, $tableConcept, $unidList;
	// 	if ($table!='default') $tableConcept = $table;
	//
	// 	$list = is_null($unid) || !is_array($unid) ? $unidList : $unid;
	//
	// 	foreach($list as $shortname => $id)
	// 	{
	// 		$sql = "UPDATE $tableConcept SET shortname = '$shortname' WHERE id = $id";
	// 		DatabaseConnection::get()->query ($sql);
	// 	}
	// }

	// //Load concepts from cache file
	// public function load()
	// {
	// 	$this->_concepts = $this->listAll();
	// 	/* Never write the cache
	// 	self::tryCreateFile($table);
	// 	$content = file_get_contents(sprintf(self::$_includePath . self::FILENAME, $table));
	// 	$json = json_decode($content);
	// 	self::$_concepts = array();
	// 	if((bool)empty($json))
	// 		return array();
	// 
	// 	foreach($json as $k=>$v)
	// 		self::$_concepts[$k] = $v;
	// 
	// 	self::$_tableLoaded = $table;
	// 	*/
	// }

	//Save concepts from cache file
	// public static function write($table = 'default')
	// {
	// 	self::tryCreateFile($table);
	// 	$filename = sprintf(self::$_includePath . self::FILENAME, $table);
	// 	$concepts = self::listAll();
	// 	file_put_contents($filename, json_encode($concepts));
	// }
	//
	// public static function tryCreateFile($table = 'default')
	// {
	// 	if(!file_exists(self::$_includePath . self::DIRNAME))
	// 		mkdir(self::$_includePath . self::DIRNAME);
	//
	// 	$filename = sprintf(self::$_includePath . self::FILENAME, $table);
	// 	if(!file_exists($filename))
	// 		touch($filename);
	// }
	
	private function checkLockWrite()
    {
        if (SandraEnv::$lockWrite) {
            throw new Exception('Can\'t write to database when lockWrite mode is enabled');
        }
    }
	
	private function sqlQuery ($sql) {
		try {
			$result = $this->pdo->query($sql);
		} catch (\PDOException $e) {
			throw new MysqlException ("request: $sql\nerror: " . $e->getMessage());
		}
		return $result;
	}
}
