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
use SandraCore\Entity;




final class CosmonautTest extends TestCase

{
    private   $sandra  ;

    public function testSystemInit(): void
    {

        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit', true);
        $this->sandra = $sandra ;

        $this->assertInstanceOf(
            \SandraCore\System::class,
            $sandra
        );

    }

    public function testSystemConceptTest(): void
    {
        $sandra = new SandraCore\System('_phpUnit', true);;
        /** @var SandraCore\System  $sandra */

        $sandraSC = $sandra->systemConcept ;

        //Make sure system concept is working properly
        $spaceshipUnid = $sandraSC->get('spaceship');

        //The creation of a new system concept returns an the id of the concept
       // $this->assertIsInt($spaceshipUnid);

        //creation of a new system concept spaceship is
        $this->assertEquals($sandraSC->get('spaceship'),$spaceshipUnid);

        //creation test test if case is different still return same concept id
        $this->assertEquals($sandraSC->get('Spaceship'),$sandra->systemConcept->get('spaceship'));

        //Ovni shouldn't exist yet so return null
        $this->assertNull($sandraSC->tryGetSC('Ovni'));


        $ovniUnid = $sandraSC->get('OVNI');

        //Ovni exists now
        $this->assertNotNull($sandraSC->tryGetSC('Ovni'));

        //test if did autoincrement concepts
        $this->assertGreaterThan($spaceshipUnid,$ovniUnid);


    }


}