<?php
namespace SandraCore;
use PDO;

class PdoConnexionWrapper
{
    private $pdo;
    
    public $host;
    public $database;
    
    public function __construct($host, $database, $user, $password)
    {
        if ($this->pdo) $this->pdo = null;
        $pdo = new PDO("mysql:host=$host;dbname=$database", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
        
        $this->database = $database;
        $this->host = $host;
    }
    
    /*
     * @return PDO  The pdo instance held by this wrapper
     */
    public function get()
    {
        return $this->pdo;
    }
}
