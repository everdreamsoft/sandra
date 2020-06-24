<?php
/**
 * Created by PhpStorm.
 * User: shabanshaame
 * Date: 03/10/2019
 * Time: 17:11
 */

use PHPUnit\Framework\TestCase;


class NotaryTest extends TestCase
{


    public function testLog()
    {

        //die(TestService::class);
        $testDisplayer = new DisplayerTest();
        //$testDisplayer-
        $sandra = TestService::getFlushTestDatagraph();
        TestService::getDatagraph();

        TestService::getDatagraph();


    }

}
