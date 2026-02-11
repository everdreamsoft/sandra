<?php
declare(strict_types=1);

namespace SandraCore;

use Exception;

/**
 * Logger that outputs to STDOUT/STDERR for development and debugging.
 */
class ConsoleLogger implements ILogger
{
    private bool $logQueries;

    public function __construct(bool $logQueries = true)
    {
        $this->logQueries = $logQueries;
    }

    public function info(string $message): void
    {
        fwrite(STDOUT, "[INFO] $message\n");
    }

    public function error(Exception $exception): void
    {
        fwrite(STDERR, "[ERROR] " . $exception->getMessage() . "\n");
    }

    public function query(string $query, float $executionTime, ?Exception $error = null): void
    {
        if (!$this->logQueries) {
            return;
        }

        $timeMs = round($executionTime * 1000, 2);
        $status = $error ? "FAIL" : "OK";

        fwrite(STDOUT, "[SQL] [{$status}] ({$timeMs}ms) $query\n");

        if ($error) {
            fwrite(STDERR, "[SQL ERROR] " . $error->getMessage() . "\n");
        }
    }
}
