<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 29.03.20
 * Time: 16:42
 */

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload


class MyService
{

    public static function getFlushTestDatagraph()
    {

        $sandraToFlush = new SandraCore\System('phpUnit__', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit__', true);

        return $system;
    }

    public static function getDatagraph()
    {

        $system = new \SandraCore\System('phpUnit__', true);

        return $system;

    }


}