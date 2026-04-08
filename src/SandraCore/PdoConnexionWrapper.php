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

    private string $user;
    private string $password;
    private ?DatabaseDriverInterface $driver;

    public function __construct(string $host, string $database, string $user, string $password, ?DatabaseDriverInterface $driver = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->driver = $driver;
        $this->database = $database;
        $this->host = $host;

        $this->pdo = $this->createPdo();
    }

    public function get(): PDO
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            // Connection lost (MySQL gone away, timeout, etc.) — reconnect
            $this->pdo = $this->createPdo();
        }
        return $this->pdo;
    }

    private function createPdo(): PDO
    {
        if ($this->driver !== null) {
            $dsn = $this->driver->getDsn($this->host, $this->database);
            if ($this->driver->getName() === 'sqlite') {
                $pdo = new PDO($dsn);
            } else {
                $pdo = new PDO($dsn, $this->user, $this->password);
            }
        } else {
            $pdo = new PDO("mysql:host={$this->host};dbname={$this->database}", $this->user, $this->password);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
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
