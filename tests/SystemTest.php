<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\System;
use SandraCore\Setup;
use SandraCore\EntityFactory;
use SandraCore\NullLogger;
use SandraCore\FileLogger;
use SandraCore\ConsoleLogger;
use SandraCore\Logger;
use SandraCore\Exception\CriticalSystemException;


final class SystemTest extends TestCase
{

    public function testLoggerDefault()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        // Default logger should be Logger (the no-op)
        $this->assertInstanceOf(Logger::class, System::$sandraLogger);
    }

    public function testLoggerInjection()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);

        $nullLogger = new NullLogger();
        $sandra = new System('_phpUnit', true, '127.0.0.1', 'sandra', 'root', '', $nullLogger);

        $this->assertInstanceOf(NullLogger::class, System::$sandraLogger);
    }

    public function testNullLoggerImplementsInterface()
    {
        $logger = new NullLogger();
        $this->assertInstanceOf(\SandraCore\ILogger::class, $logger);

        // Should not throw - just no-ops
        $logger->info('test message');
        $logger->error(new \Exception('test'));
        $logger->query('SELECT 1', 0.001);
        $logger->query('SELECT 1', 0.001, new \Exception('err'));

        $this->assertTrue(true); // If we get here, no-op works
    }

    public function testFileLoggerWritesToFile()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sandra_log_');
        $logger = new FileLogger($tmpFile, true);

        $logger->info('test info message');
        $logger->error(new \Exception('test error'));
        $logger->query('SELECT 1', 0.005);
        $logger->query('SELECT 1', 0.005, new \Exception('sql error'));

        $content = file_get_contents($tmpFile);
        $this->assertStringContainsString('[INFO] test info message', $content);
        $this->assertStringContainsString('[ERROR] test error', $content);
        $this->assertStringContainsString('[SQL]', $content);
        $this->assertStringContainsString('[SQL_ERROR]', $content);

        unlink($tmpFile);
    }

    public function testFileLoggerSkipsQueriesWhenDisabled()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sandra_log_');
        $logger = new FileLogger($tmpFile, false);

        $logger->query('SELECT 1', 0.005);
        $content = file_get_contents($tmpFile);
        $this->assertEmpty($content);

        // Errors should still be logged even with logQueries=false
        $logger->query('SELECT 1', 0.005, new \Exception('sql error'));
        $content = file_get_contents($tmpFile);
        $this->assertStringContainsString('[SQL_ERROR]', $content);

        unlink($tmpFile);
    }

    public function testConnections()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        // Test getConnection returns a valid PDO instance
        $pdo = $sandra->getConnection();
        $this->assertInstanceOf(\PDO::class, $pdo);

        // Test the static pdo wrapper
        $this->assertNotNull(System::$pdo);
        $this->assertSame($pdo, System::$pdo->get());
    }

    public function testSystemErrorBelowKillLevel()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        // Level 2 is below errorLevelToKill (3), should return message
        $msg = $sandra->systemError(1, self::class, 2, "low level warning");
        $this->assertEquals("low level warning", $msg);
    }

    public function testSystemErrorAboveKillLevel()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        // Level 3 equals errorLevelToKill, should throw
        $this->expectException(CriticalSystemException::class);
        $sandra->systemError(1, self::class, 3, "critical error");
    }

    public function testKillingProcessLevel()
    {
        $sandraToFlush = new System('_phpUnit', true);
        Setup::flushDatagraph($sandraToFlush);
        $sandra = new System('_phpUnit', true);

        $this->expectException(CriticalSystemException::class);
        $this->expectExceptionMessage("fatal message");
        $sandra->killingProcessLevel(1, self::class, 5, "fatal message");
    }

    public function testTableNames()
    {
        $sandra = new System('_phpUnit', true);

        $this->assertEquals('_phpUnit_SandraConcept', $sandra->conceptTable);
        $this->assertEquals('_phpUnit_SandraTriplets', $sandra->linkTable);
        $this->assertEquals('_phpUnit_SandraReferences', $sandra->tableReference);
        $this->assertEquals('_phpUnit_SandraDatastorage', $sandra->tableStorage);
        $this->assertEquals('_phpUnit_SandraConfig', $sandra->getTableConf());
    }

    public function testInstanceIdUniqueness()
    {
        $sandra1 = new System('_phpUnit', true);
        $sandra2 = new System('_phpUnit', true);

        $this->assertNotEmpty($sandra1->instanceId);
        $this->assertNotEmpty($sandra2->instanceId);
        // Instance IDs should be unique (extremely high probability)
        $this->assertNotEquals($sandra1->instanceId, $sandra2->instanceId);
    }

    public function testGetTableConf()
    {
        $sandra = new System('test_', true);
        $this->assertEquals('test__SandraConfig', $sandra->getTableConf());
    }
}
