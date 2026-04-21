<?php
declare(strict_types=1);

namespace SandraCore\Driver;

interface DatabaseDriverInterface
{
    public function getDsn(string $host, string $database): string;

    /**
     * @param string $tableType One of: 'concept', 'triplet', 'reference', 'storage', 'config', 'embedding'
     */
    public function getCreateTableSQL(string $tableName, string $tableType): string;

    public function getUpsertReferenceSQL(string $table): string;

    public function getUpsertTripletSQL(string $table): string;

    public function getUpsertStorageSQL(string $table): string;

    public function getUpsertEmbeddingSQL(string $table): string;

    public function getRandomOrderSQL(): string;

    public function getCastNumericSQL(string $column): string;

    public function getName(): string;
}
