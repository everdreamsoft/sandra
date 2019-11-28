<?php
/**
 * Created by PhpStorm.
 * User: shabanshaame
 * Date: 03/10/2019
 * Time: 17:11
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{


    public function testDelete()
    {

        $sandraToFlush = new SandraCore\System('phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit', true);

        $system = new \SandraCore\System('phpUnit', true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);
        $starFactory = new \SandraCore\EntityFactory('star', 'atlasFile', $system);
        $atlasFactory = new \SandraCore\EntityFactory(null, 'atlasFile', $system);


        $entityFactory2 = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);


        $mercury = $entityFactory->createNew(["name" => "Mercury", "revolution[day]" => 88, "rotation[day]" => 59, "mass[earth]" => 0.06]);
        $entityFactory->createNew(["name" => "Venus", "revolution[day]" => 225, "rotation[day]" => 243, "mass[earth]" => 0.82]);
        $entityFactory->createNew(["name" => "Earth", "revolution[day]" => 365, "rotation[h]" => 24, "mass[earth]" => 1]);

        $starFactory->createNew(["name" => "Sun"]);


        $mercury->delete();
        $emptyMercury = $entityFactory2->first("name", "mercury");

        $this->assertEmpty($emptyMercury);


    }

}
