<?php
declare(strict_types=1);

namespace SandraCore\Validation;

use SandraCore\EntityFactory;

class Validator
{
    private array $rules;
    private array $customRules = [];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function addRule(string $name, callable $fn): void
    {
        $this->customRules[$name] = $fn;
    }

    public function validate(array $data, EntityFactory $factory): void
    {
        $errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $param = null;
                if (str_contains($rule, ':')) {
                    [$rule, $param] = explode(':', $rule, 2);
                }

                $passed = $this->checkRule($rule, $value, $param, $data, $factory);
                if (!$passed) {
                    $errors[$field][] = $param !== null ? "{$rule}:{$param}" : $rule;
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function checkRule(string $rule, mixed $value, ?string $param, array $data, EntityFactory $factory): bool
    {
        if (isset($this->customRules[$rule])) {
            return (bool)($this->customRules[$rule])($value, $param, $data, $factory);
        }

        return match ($rule) {
            'required' => $value !== null && $value !== '',
            'string' => $value === null || is_string($value),
            'numeric' => $value === null || $value === '' || is_numeric($value),
            'integer' => $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_INT) !== false,
            'email' => $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => $value === null || $value === '' || (is_numeric($value) && (float)$value >= (float)$param),
            'max' => $value === null || $value === '' || (is_numeric($value) && (float)$value <= (float)$param),
            'maxlength' => $value === null || mb_strlen((string)$value) <= (int)($param ?? 255),
            'unique' => $this->checkUnique($value, $data, $factory),
            default => true,
        };
    }

    private function checkUnique(mixed $value, array $data, EntityFactory $factory): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $field = null;
        foreach ($this->rules as $f => $rules) {
            if (in_array('unique', $rules, true)) {
                $field = $f;
                break;
            }
        }

        if ($field === null) {
            return true;
        }

        $existing = $factory->getAllWith($field, $value);
        return !is_array($existing) || empty($existing);
    }
}
