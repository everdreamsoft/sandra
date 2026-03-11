<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\EntityFactory;
use SandraCore\Setup;
use SandraCore\System;

/**
 * Base test case for Sandra graph database tests.
 *
 * Provides a clean, flushed datagraph and common helpers
 * for creating factories and entities in tests.
 */
abstract class SandraTestCase extends TestCase
{
    protected System $system;

    protected function setUp(): void
    {
        parent::setUp();
        $dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
        $db = getenv('SANDRA_DB') ?: 'sandra';
        $dbUser = getenv('SANDRA_DB_USER') ?: 'root';
        $dbPass = ($v = getenv('SANDRA_DB_PASS')) !== false ? $v : '';

        $flusher = new System('phpUnit_', true, $dbHost, $db, $dbUser, $dbPass);
        Setup::flushDatagraph($flusher);
        // Reset static PDO so fresh System gets a new connection
        $ref = new \ReflectionProperty(System::class, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        $this->system = new System('phpUnit_', true, $dbHost, $db, $dbUser, $dbPass);
    }

    /**
     * Create an EntityFactory with the given entity type and file.
     */
    protected function createFactory(string $isa, string $file): EntityFactory
    {
        return new EntityFactory($isa, $file, $this->system);
    }

    /**
     * Create and populate an EntityFactory.
     */
    protected function createPopulatedFactory(string $isa, string $file): EntityFactory
    {
        $factory = $this->createFactory($isa, $file);
        $factory->populateLocal();
        return $factory;
    }
}
