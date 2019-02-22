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
use SandraCore\Entity;




final class ForeignEntityAdapterTest extends TestCase
{
    private $baseFactory ;

    public function testCanBeCreated(): void    {

        $sandraToFlush = new SandraCore\System('_phpUnit',true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit',true);


        $createdClass =  new \SandraCore\ForeignEntityAdapter('http://www.google.com','',$sandra);



        $this->assertInstanceOf(
            \SandraCore\ForeignEntityAdapter::class,
            $createdClass
        );
    }





}