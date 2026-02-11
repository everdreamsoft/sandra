<?php
declare(strict_types=1);

namespace SandraCore\Validation;

use SandraCore\Exception\SandraException;

class ValidationException extends SandraException
{
    private array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct($this->getFirstError());
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): string
    {
        foreach ($this->errors as $field => $rules) {
            $rule = is_array($rules) ? reset($rules) : $rules;
            return "Validation failed for '{$field}': {$rule}";
        }
        return 'Validation failed';
    }
}
