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





final class EntityCTest extends TestCase
{

    public function testEntity()
    {

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



    }

    public function testGetOrCreateFromRef()
    {

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



    }


}