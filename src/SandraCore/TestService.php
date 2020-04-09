<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 29.03.20
 * Time: 16:42
 */

namespace SandraCore;


class TestService
{

    public static function getFlushTestDatagraph()
    {

        $sandraToFlush = new System('phpUnit__', true);
        Setup::flushDatagraph($sandraToFlush);
        $system = new System('phpUnit__', true);

        return $system;
    }

    public static function getDatagraph()
    {

        $system = new \SandraCore\System('phpUnit__', true);

        return $system;

    }


}