<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;

/**
 * Manages database transactions with nested depth support.
 *
 * Supports nested begin/commit calls - only the outermost pair
 * actually starts/commits the real database transaction.
 * Use wrap() for automatic commit/rollback based on success/failure.
 */
class TransactionManager
{
    private static int $depth = 0;
    private static bool $started = false;
    private static ?PDO $pdo = null;

    public static function begin(PDO $pdo): void
    {
        if (self::$depth === 0) {
            $pdo->beginTransaction();
            System::$sandraLogger->query("Begin Transaction", 0);
            self::$pdo = $pdo;
            self::$started = true;
        }
        self::$depth++;
    }

    public static function commit(): void
    {
        if (self::$depth <= 0) {
            return;
        }
        self::$depth--;
        if (self::$depth === 0 && self::$started) {
            self::$pdo->commit();
            System::$sandraLogger->query("Commit", 0);
            self::$started = false;
        }
    }

    public static function rollback(): void
    {
        if (self::$started && self::$pdo !== null) {
            self::$pdo->rollBack();
            System::$sandraLogger->query("Rollback", 0);
        }
        self::$depth = 0;
        self::$started = false;
    }

    public static function isActive(): bool
    {
        return self::$started;
    }

    /**
     * Reset internal state. Intended for testing only.
     */
    public static function reset(): void
    {
        self::$depth = 0;
        self::$started = false;
        self::$pdo = null;
    }

    /**
     * Execute a callable within a transaction.
     * Commits on success, rolls back on exception.
     *
     * @return mixed The return value of the callable
     * @throws \Throwable Re-throws any exception after rollback
     */
    public static function wrap(PDO $pdo, callable $fn): mixed
    {
        self::begin($pdo);
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }
}
