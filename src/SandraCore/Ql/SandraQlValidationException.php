<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/** AST failed structural validation against the SandraQL schema. */
class SandraQlValidationException extends \RuntimeException
{
    public string $path;

    public function __construct(string $message, string $path)
    {
        parent::__construct("$message (at $path)");
        $this->path = $path;
    }
}
