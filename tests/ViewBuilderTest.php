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

        $system = new \SandraCore\System('phpUnit_',true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);
        $entityFactory->populateLocal();


        //should create a new planet
        $jupiterEntity = $entityFactory->getOrCreateFromRef('name', 'jupiter');

        $this->assertInstanceOf(
            \SandraCore\Entity::class,
            $jupiterEntity
        );

        //we add a paremeter
        $radiusRef = $jupiterEntity->getOrInitReference('radius[km]',69911);

        $this->assertInstanceOf(
            \SandraCore\Reference::class,
            $radiusRef
        );


        $entityFactory->createViewTable("myfantastic4");


        
        
        
    }

}
