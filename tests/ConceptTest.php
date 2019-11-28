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
use SandraCore\ConceptManager;
use SandraCore\Entity;


final class ConceptTest extends TestCase
{

    public function testConcept()
    {
        $sandraToFlush = new SandraCore\System('phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $sandra = new \SandraCore\System('phpUnit', true);

        $animalFactory = new \SandraCore\EntityFactory('cat','catFile',$sandra);
        $localAnimalFactory = clone $animalFactory ;

        $ownerFactory = new \SandraCore\EntityFactory('person','ownerFile',$sandra);
        $localOwnerFactory = clone $ownerFactory ;

        $jack = $ownerFactory->createNew(array('name'=>'Jack','lastName'=>'Jackson'));
        $john = $ownerFactory->createNew(array('name'=>'John','lastName'=>'Doe'));

        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Jackson'),array('ownedBy'=>$jack));
        $animalFactory->createNew(array('name'=>'Blackie'));
        $animalFactory->createNew(array('name'=>'Tabasco'));
        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Doe'),array('ownedBy'=>$john));

       $entities = $animalFactory->getEntities();
       $firstCat = reset($entities);
       /** @var Entity $firstCat */
      $firstCatId = $firstCat->subjectConcept->idConcept ;


        $sandraVirgin = new \SandraCore\System('phpUnit', true);


        $concept = $sandraVirgin->conceptFactory->getConceptFromShortnameOrId($firstCatId);
        $refs = $concept->getReferences(1);

        $this->assertEquals(1, 1); //No test





    }




}