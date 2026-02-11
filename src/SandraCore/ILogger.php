<?php
declare(strict_types=1);

namespace SandraCore;

use Exception;

interface ILogger
{
    /**
     * Logs message as info.
     */
    public function info(string $message): void;

    /**
     * Logs error.
     */
    public function error(Exception $exception): void;

    /**
     * Logs sql query.
     */
    public function query(string $query, float $executionTime, ?Exception $error = null): void;
}
