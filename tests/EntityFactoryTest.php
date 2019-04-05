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





final class EntityFactoryTest extends TestCase
{

    public function testEntityFactory()
    {
        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);


       $this->assertEquals('should implement basic tests','should implement basic tests');





    }

    public function testSetFilter()
    {

        $system = new \SandraCore\System('phpUnit_',true);
        $alphabetFactory = new \SandraCore\EntityFactory('algebra','algebraFile',$system);

        $alphabetFactoryEmpty = clone $alphabetFactory ;
        $allImpliesB =  new \SandraCore\EntityFactory('algebra','algebraFile',$system);

        $a = $alphabetFactory->createNew(array('name'=>'a'));
        $b = $alphabetFactory->createNew(array('name'=>'b'));
        $c = $alphabetFactory->createNew(array('name'=>'c'));
        $d = $alphabetFactory->createNew(array('name'=>'d'));
        $e = $alphabetFactory->createNew(array('name'=>'e'));

                $a->setBrotherEntity('implies',$b,null);
                $a->setBrotherEntity('implies',$c,null);

                $e->setBrotherEntity('implies',$b,null);
                $d->setBrotherEntity('implies',$b,null);




                $alphabetFactory->populateLocal();
                $alphabetFactory->getTriplets();
                $alphabetFactory->dumpMeta();

        $this->assertCount(5,$alphabetFactory->entityArray);


        $factoryWithOtherIsa = new \SandraCore\EntityFactory('somethingElse','algebraFile',$system);

        $allImpliesB->setFilter('implies',$b);
        $allImpliesB->populateLocal();
        $factoryWithOtherIsa->populateLocal();

        $this->assertCount(0,$factoryWithOtherIsa->entityArray);
        $this->assertCount(3,$allImpliesB->entityArray);









    }




}