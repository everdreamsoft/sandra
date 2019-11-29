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
        for ($i = 0; $i < 200; $i++) {
           $system = new System();

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

           if ($i==9){

               $intermedMemory = memory_get_usage();
               echo"intermed memory = $intermedMemory".PHP_EOL;

           }
           $system->destroy();


        }
      unset($system);



        $afterMemory = memory_get_usage();
        echo"final memory    = $afterMemory".PHP_EOL;
        $delta = $afterMemory - $intermedMemory ;
        echo "delta = $delta";

        $this->assertLessThan(20000,$delta, "Memory delta = ".$delta);

    }



}
