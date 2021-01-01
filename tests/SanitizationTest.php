<?php
/**
 * Created by PhpStorm.
 * User: shabanshaame
 * Date: 03/10/2019
 * Time: 17:11
 */

use PHPUnit\Framework\TestCase;
use SandraCore\DatabaseAdapter;

class SanitizationTest extends TestCase
{

    public function testSanitization()
    {

        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_', true);

        foreach ($this->edgeRequestString() as $edge) {
            $findPlayer = DatabaseAdapter::searchConcept($system, $edge, null, 0, $edge);
            \SandraCore\CommonFunctions::somethingToConceptId($edge, $system);
            $system->systemConcept->get($edge);
            $this->assertEmpty($findPlayer);


        }


    }

    public function edgeRequestString()
    {

        $edgeRequests = [
            'name" OR 1 = 1',
            "Edge ' OR",

        ];
        return $edgeRequests;

    }

}
