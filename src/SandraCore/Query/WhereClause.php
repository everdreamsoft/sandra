<?php
declare(strict_types=1);

namespace SandraCore\Query;

class WhereClause
{
    public const TYPE_BROTHER = 'brother';
    public const TYPE_REF = 'ref';

    public string $type;
    public string $field;
    public string $operator;
    public mixed $value;
    public bool $exclusion;

    public function __construct(string $type, string $field, string $operator, mixed $value, bool $exclusion = false)
    {
        $this->type = $type;
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
        $this->exclusion = $exclusion;
    }
}
