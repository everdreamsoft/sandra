<?php

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 12:44
 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use InnateSkills\SandraHealth\MemoryManagement;
use PHPUnit\Framework\TestCase;





final class HealthTest extends TestCase
{



    public function testConnections()
    {


        $memory = MemoryManagement::getSystemMemory();

        $this->assertStringContainsString('mb',$memory);


    }










}