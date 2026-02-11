<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Handles SQL execution with logging and error handling.
 * Eliminates the repeated prepare/execute/log/catch boilerplate in DatabaseAdapter.
 */
class QueryExecutor
{
    /**
     * Execute a SQL statement and return the PDOStatement.
     * Handles prepare, bind, execute, logging, and error handling.
     *
     * @param PDO $pdo
     * @param string $sql
     * @param array $params Associative array of [:param => value] or [:param => [value, PDO::PARAM_*]]
     * @return PDOStatement|null Null on error
     */
    public static function execute(PDO $pdo, string $sql, array $params = []): ?PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $stmt->bindValue($key, $value[0], $value[1]);
                } else {
                    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
            }
            $stmt->execute();
        } catch (PDOException $exception) {
            System::$sandraLogger->query($sql, microtime(true) - $start, $exception);
            System::sandraException($exception);
            return null;
        }

        System::$sandraLogger->query($sql, microtime(true) - $start);

        return $stmt;
    }

    /**
     * Execute and return all rows as associative arrays.
     */
    public static function fetchAll(PDO $pdo, string $sql, array $params = []): ?array
    {
        $stmt = self::execute($pdo, $sql, $params);
        return $stmt?->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute an INSERT and return the last insert ID.
     */
    public static function insert(PDO $pdo, string $sql, array $params = []): ?string
    {
        $stmt = self::execute($pdo, $sql, $params);
        if ($stmt === null) {
            return null;
        }
        return $pdo->lastInsertId();
    }
}
