<?php
declare(strict_types=1);

namespace SandraCore\Import;

use SandraCore\Entity;

class ImportResult
{
    /** @var Entity[] */
    private array $created = [];

    /** @var array<int, array{row: int, data: array, error: string}> */
    private array $errors = [];

    private int $totalRows = 0;

    public function addCreated(Entity $entity): void
    {
        $this->created[] = $entity;
    }

    public function addError(int $row, array $data, string $error): void
    {
        $this->errors[] = ['row' => $row, 'data' => $data, 'error' => $error];
    }

    public function setTotalRows(int $total): void
    {
        $this->totalRows = $total;
    }

    /** @return Entity[] */
    public function getCreated(): array
    {
        return $this->created;
    }

    public function getCreatedCount(): int
    {
        return count($this->created);
    }

    /** @return array<int, array{row: int, data: array, error: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function isFullySuccessful(): bool
    {
        return empty($this->errors) && $this->totalRows > 0;
    }
}
