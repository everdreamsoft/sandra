<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use SandraCore\EntityFactory;
use SandraCore\Setup;
use SandraCore\System;

class FiltersTest extends TestCase
{

    public function testFilters()
    {

        $sandraToFlush = new System('phpUnit_', true);
        Setup::flushDatagraph($sandraToFlush);

        $system = new System('phpUnit_', true);

        $chocolateBarFactory = new EntityFactory('chocolateBar', 'chocolateBarFile', $system);
        $chocolateBarFactory->populateLocal();
        $chocolateBar = $chocolateBarFactory->getOrCreateFromRef('name', 'newBar');

        $ovomaltineFactory = new EntityFactory('ovomaltine', 'chocolateFile', $system);
        $ovomaltineFactory->populateLocal();
        $ovomaltine = $ovomaltineFactory->getOrCreateFromRef('name', 'crunchy');

        $othersFactory = new EntityFactory('otherChocolates', 'chocolateFile', $system);
        $othersFactory->populateLocal();
        $otherChocolates = $othersFactory->getOrCreateFromRef('name', 'milky');

        $chocolateBar->setBrotherEntity('taste', $ovomaltine, []);
        $chocolateBar->setBrotherEntity('taste', $otherChocolates, []);

        $chocolateBarFactory = new EntityFactory('chocolateBar', 'chocolateBarFile', $system);
        $chocolateBarFactory->joinFactory('taste', $ovomaltineFactory);
        $chocolateBarFactory->setFilter('taste', $ovomaltine);
        $chocolateBarFactory->populateLocal();
        $chocolateBarFactory->joinPopulate();

        $chocolateBars = $chocolateBarFactory->getEntities();
        $this->assertNotEmpty($chocolateBars);
        $this->assertCount(1, $chocolateBars);

        $chocolateBar = reset($chocolateBars);

        // 2 entities on verb
        $tastes = $chocolateBar->getJoinedEntities('taste');

        // both entities are 'ovomaltine'
        $ovomaltines = [];
        foreach ($tastes as $taste){
            if($taste->factory->entityIsa == $ovomaltineFactory->entityIsa){
                $ovomaltines[] = $taste;
            }
        }

        $this->assertCount(1, $ovomaltines);

    }

}