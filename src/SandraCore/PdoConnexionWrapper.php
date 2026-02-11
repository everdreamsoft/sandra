<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;
use SandraCore\Driver\DatabaseDriverInterface;

class PdoConnexionWrapper implements DatabaseConnection
{
    private PDO $pdo;

    public string $host;
    public string $database;

    public function __construct(string $host, string $database, string $user, string $password, ?DatabaseDriverInterface $driver = null)
    {
        if ($driver !== null) {
            $dsn = $driver->getDsn($host, $database);
            if ($driver->getName() === 'sqlite') {
                $pdo = new PDO($dsn);
            } else {
                $pdo = new PDO($dsn, $user, $password);
            }
        } else {
            $pdo = new PDO("mysql:host=$host;dbname=$database", $user, $password);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;

        $this->database = $database;
        $this->host = $host;
    }

    public function get(): PDO
    {
        return $this->pdo;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getHost(): string
    {
        return $this->host;
    }
}
