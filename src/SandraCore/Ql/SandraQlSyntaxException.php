<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/** Lexer / parser error with source position. */
class SandraQlSyntaxException extends \RuntimeException
{
    public int $sourceLine;
    public int $sourceColumn;

    public function __construct(string $message, int $line, int $column)
    {
        parent::__construct("$message (line $line, column $column)");
        $this->sourceLine = $line;
        $this->sourceColumn = $column;
    }
}
