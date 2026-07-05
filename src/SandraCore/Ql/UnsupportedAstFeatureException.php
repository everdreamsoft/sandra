<?php
declare(strict_types=1);

namespace SandraCore\Ql;

/**
 * The executor received a valid AST node it does not support (yet).
 * The JS library (@everdreamsoft/sandra) supports the full v1 AST including
 * OR trees; the PHP executor currently supports conjunctive (AND-only)
 * queries — this exception keeps the "unified" claim honest.
 */
class UnsupportedAstFeatureException extends \RuntimeException
{
    public string $feature;

    public function __construct(string $feature)
    {
        parent::__construct("Unsupported SandraQL AST feature: $feature");
        $this->feature = $feature;
    }
}
