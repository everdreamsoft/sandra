<?php
/**
 * Created by PhpStorm.
 * User: shabanshaame
 * Date: 03/10/2019
 * Time: 17:11
 */

use PHPUnit\Framework\TestCase;
use SandraCore\CommonFunctions;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\System;

class MemoryTest extends TestCase
{


    public function testViewBuilder()
    {

        //$sandraToFlush = new SandraCore\System('phpUnit', true);
        //\SandraCore\Setup::flushDatagraph($sandraToFlush);

        $initialMemory = memory_get_usage();


        $this->assertEquals($initialMemory,$initialMemory);
        echo"initial memory  = $initialMemory".PHP_EOL;
       // $system = new \SandraCore\System('phpUnit', true);
        //$system = null ;
        $x='';
        for ($i = 0; $i < 50; $i++) {
           $system = new System('', true);

            $me = CommonFunctions::somethingToConceptId("me",$system);

           $myFactory = new EntityFactory("hello factory",'here',$system);
           $myFactory->createNew(["hello"=>"world"]);
          $entity = $myFactory->getOrCreateFromRef("hey",'you');
          $entity->setBrotherEntity("yes",$me,['hey'=>'them']);


          $joinedFactory = new EntityFactory('someone','people',$system);
         // $joinedFactory->createNew()
            //join factory test

           $myFactory->populateLocal();
            //

            if ($i == 20) {

               $intermedMemory = memory_get_usage();
               echo"intermed memory = $intermedMemory".PHP_EOL;

           }
           $system->destroy();


        }
      unset($system);
        gc_collect_cycles();

        $afterMemory = memory_get_usage();
        echo"final memory    = $afterMemory".PHP_EOL;
        echo \InnateSkills\SandraHealth\MemoryManagement::echoMemoryUsage() . PHP_EOL;
        $delta = $afterMemory - $intermedMemory ;
        echo "delta = $delta";

        // Smoke test: memory shouldn't balloon between iteration 20 and 50.
        // A small amount of steady growth per iteration is acceptable;
        // the goal is to catch runaway leaks, not penalize normal allocator behavior.
        $this->assertLessThan(2_000_000, $delta, "Memory delta = " . $delta);

    }



}
