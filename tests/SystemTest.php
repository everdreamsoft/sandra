<?php

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 12:44
 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use PHPUnit\Framework\TestCase;





final class SystemTest extends TestCase
{

    public function testLogger()
    {

        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit', true);
        $this->sandra = $sandra ;


        $sandra->systemError(1,self::class,2,"my jmessage");
        $logger = $sandra::$logger;




    }










}