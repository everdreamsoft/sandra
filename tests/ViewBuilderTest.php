<?php
/**
 * Created by PhpStorm.
 * User: shabanshaame
 * Date: 03/10/2019
 * Time: 17:11
 */

use PHPUnit\Framework\TestCase;

class ViewBuilderTest extends TestCase
{


    public function testViewBuilder(){

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


        $this->assertInstanceOf(
            \SandraCore\Entity::class,
            $mercury
        );


        $entityFactory2->populateLocal();
        $entityFactory2->createViewTable("planet_in_atlas");

        $atlasFactory->populateLocal();
        $atlasFactory->createViewTable("atlas");


        
        
    }

}
