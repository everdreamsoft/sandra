<?php
declare(strict_types=1);

namespace SandraCore;

use Exception;

/**
 * Logger that writes to a file for production use.
 */
class FileLogger implements ILogger
{
    private string $filePath;
    private bool $logQueries;

    public function __construct(string $filePath, bool $logQueries = false)
    {
        $this->filePath = $filePath;
        $this->logQueries = $logQueries;
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(Exception $exception): void
    {
        $this->write('ERROR', $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    }

    public function query(string $query, float $executionTime, ?Exception $error = null): void
    {
        if (!$this->logQueries && !$error) {
            return;
        }

        $timeMs = round($executionTime * 1000, 2);

        if ($error) {
            $this->write('SQL_ERROR', "({$timeMs}ms) $query — " . $error->getMessage());
        } elseif ($this->logQueries) {
            $this->write('SQL', "({$timeMs}ms) $query");
        }
    }

    private function write(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] [$level] $message\n";
        file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
    }
}
