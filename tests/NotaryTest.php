<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\System;
use SandraCore\Setup;
use SandraCore\EntityFactory;
use SandraCore\TransactionManager;


class NotaryTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        TransactionManager::reset();
    }

    public function testTransactionManagerBeginAndCommit()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        $this->assertFalse(TransactionManager::isActive());

        TransactionManager::begin($pdo);
        $this->assertTrue(TransactionManager::isActive());

        TransactionManager::commit();
        $this->assertFalse(TransactionManager::isActive());
    }

    public function testTransactionManagerNestedDepth()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        // Nested begin should not start a second transaction
        TransactionManager::begin($pdo);
        TransactionManager::begin($pdo);
        $this->assertTrue(TransactionManager::isActive());

        // First commit should not close the transaction (depth > 0)
        TransactionManager::commit();
        $this->assertTrue(TransactionManager::isActive());

        // Second commit closes it
        TransactionManager::commit();
        $this->assertFalse(TransactionManager::isActive());
    }

    public function testTransactionManagerRollback()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        TransactionManager::begin($pdo);
        $this->assertTrue(TransactionManager::isActive());

        TransactionManager::rollback();
        $this->assertFalse(TransactionManager::isActive());
    }

    public function testTransactionManagerWrap()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        $result = TransactionManager::wrap($pdo, function () {
            return 42;
        });

        $this->assertEquals(42, $result);
        $this->assertFalse(TransactionManager::isActive());
    }

    public function testTransactionManagerWrapRollsBackOnException()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        $caught = false;
        try {
            TransactionManager::wrap($pdo, function () {
                throw new \RuntimeException('test error');
            });
        } catch (\RuntimeException $e) {
            $caught = true;
            $this->assertEquals('test error', $e->getMessage());
        }

        $this->assertTrue($caught);
        $this->assertFalse(TransactionManager::isActive());
    }

    public function testTransactionWrapWithEntityCreation()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $pdo = $sandra->getConnection();

        // Create entity inside a transaction
        TransactionManager::wrap($pdo, function () use ($sandra) {
            $factory = new EntityFactory('animal', 'animalFile', $sandra);
            $factory->createNew(['name' => 'Fido'], null, false);
            $factory->createNew(['name' => 'Rex'], null, false);
        });

        // Verify entities were committed
        $factory = new EntityFactory('animal', 'animalFile', $sandra);
        $factory->populateLocal();
        $entities = $factory->getEntities();
        $this->assertCount(2, $entities);
    }
}
