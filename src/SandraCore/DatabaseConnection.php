<?php
declare(strict_types=1);

namespace SandraCore;

use PDO;

/**
 * Interface for database connections.
 * Allows swapping implementations for testing or multi-tenancy.
 */
interface DatabaseConnection
{
    public function get(): PDO;

    public function getDatabase(): string;

    public function getHost(): string;
}
