<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;

class PdoConnexionWrapper implements DatabaseConnection
{
    private PDO $pdo;

    public string $host;
    public string $database;

    public function __construct(string $host, string $database, string $user, string $password)
    {
        $pdo = new PDO("mysql:host=$host;dbname=$database", $user, $password);
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
