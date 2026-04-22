<?php
declare(strict_types=1);

namespace SandraCore;

/**
 * System subclass for legacy Sandra 7 databases.
 *
 * Sandra 7 uses a different table naming convention:
 *   Concept{_env}, Link{_env}, References{_env}, aux_dataStorage{_env}, system_configuration{_env}
 * When env is empty, the suffix is omitted (just the base name).
 *
 * Legacy DBs have no embedding table and the shared-token table lives in the
 * modern DB, not here — so we skip both in install().
 */
class Sandra7LegacySystem extends System
{
    protected function resolveTableNames(string $env): void
    {
        $suffix = $env !== '' ? '_' . $env : '';

        $this->conceptTable = 'Concept' . $suffix;
        $this->linkTable = 'Link' . $suffix;
        $this->tableReference = 'References' . $suffix;
        $this->tableStorage = 'aux_dataStorage' . $suffix;
        $this->tableConf = 'system_configuration' . $suffix;
        // No embedding support in legacy Sandra 7
        $this->tableEmbedding = '';
    }

    public function install(): void
    {
        // Legacy tables are assumed to already exist in production.
        // If you ever need to create them from scratch, pass only the 5 core tables
        // (no embedding, no shared-token table — those live in a separate v8 DB).
        SandraDatabaseDefinition::createEnvTables(
            $this->conceptTable,
            $this->linkTable,
            $this->tableReference,
            $this->tableStorage,
            $this->tableConf
        );
    }
}
