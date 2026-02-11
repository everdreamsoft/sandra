<?php
declare(strict_types=1);

namespace SandraCore;

use Exception;

/**
 * No-op logger that silently discards all log messages.
 * Use this in production when logging is not needed.
 */
class NullLogger implements ILogger
{
    public function info(string $message): void
    {
    }

    public function error(Exception $exception): void
    {
    }

    public function query(string $query, float $executionTime, ?Exception $error = null): void
    {
    }
}
