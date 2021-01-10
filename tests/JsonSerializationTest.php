<?php

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 12:44
 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use InnateSkills\Gossiper\Gossiper;
use PHPUnit\Framework\TestCase;
use SandraCore\TestService;


final class JsonSerializationTest extends TestCase
{

    public function testReadJson()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"name"},"entityFactory":{"is_a":"cat","contained_in_file":"testFile","entityArray":
        [{"referenceArray":[{"refId":3,"concept":{"unid":0,"shortname":"name"},"value":"felix"},{"refId":4,"concept":{"unid":0,"shortname":"age"},"value":"3"}],
        "subjectUnid":1,"id":1}],"refMap":{"0":"age"}}}';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $this->assertEquals('cat', $entityFactory->entityIsa);

        $verifFactory = new \SandraCore\EntityFactory('cat', 'testFile', $sandra);
        $this->assertCount(1, $entityFactory->getEntities());
        $this->assertEquals($gossiper->createRefCount, 2);
        $this->assertEquals($gossiper->updateRefCount, 0);
        $this->assertEquals($gossiper->equalRefCount, 0);


    }

    public function testOverideRef()
    {
        $ageModif = 4;

        $sandra = TestService::getDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"name"},"entityFactory":{"is_a":"cat","contained_in_file":"testFile","entityArray":
        [{"referenceArray":[{"refId":3,"concept":{"unid":0,"shortname":"name"},"value":"felix"},{"refId":4,"concept":{"unid":0,"shortname":"age"},"value":"' . $ageModif . '"}],
        "subjectUnid":1,"id":1}],"refMap":{"0":"age"}}}';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $this->assertEquals('cat', $entityFactory->entityIsa);

        $verifFactory = new \SandraCore\EntityFactory('cat', 'testFile', $sandra);
        $verifFactory->populateLocal();


        $this->assertCount(1, $entityFactory->getEntities());
        $this->assertEquals($gossiper->createRefCount, 0);
        $this->assertEquals(1, $gossiper->updateRefCount);
        $this->assertEquals(1, $gossiper->equalRefCount);


    }

    public function testAddRef()
    {
        //we add a ref and we modify a ref


        $ageModif = 5;
        $addedRef = ',{"refId":3,"concept":{"unid":0,"shortname":"nickname"},"value":"fel"}';

        $sandra = TestService::getDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"name"},"entityFactory":{"is_a":"cat","contained_in_file":"testFile","entityArray":
        [{"referenceArray":[{"refId":3,"concept":{"unid":0,"shortname":"name"},"value":"felix"}
       ' . $addedRef . '
        ,{"refId":4,"concept":{"unid":0,"shortname":"age"},"value":"' . $ageModif . '"}],
        "subjectUnid":1,"id":1}],"refMap":{"0":"age"}}}';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $this->assertEquals('cat', $entityFactory->entityIsa);

        $verifFactory = new \SandraCore\EntityFactory('cat', 'testFile', $sandra);
        $verifFactory->populateLocal();

        $this->assertCount(1, $entityFactory->getEntities());


        $this->assertEquals(1, $gossiper->createRefCount);
        $this->assertEquals(1, $gossiper->updateRefCount);
        $this->assertEquals(1, $gossiper->equalRefCount);


    }

    public function testSeveralEntries()
    {


        $sandra = TestService::getDatagraph();

        //we update the age again back to 4 modif ref++
        $json = '{
  "gossiper": {
    "updateOnReferenceShortname": "name"
  },
  "entityFactory": {
    "is_a": "cat",
    "contained_in_file": "testFile",
    "entityArray": [
      {
        "referenceArray": [
          {
            "refId": 3,
            "concept": {
              "unid": 0,
              "shortname": "name"
            },
            "value": "felix"
          },
          {
            "refId": 4,
            "concept": {
              "unid": 0,
              "shortname": "age"
            },
            "value": "4"
          }
        ],
        "subjectUnid": 1,
        "id": 1
      },{
        "referenceArray": [
          {
            "refId": 3,
            "concept": {
              "unid": 0,
              "shortname": "name"
            },
            "value": "jasper"
          },
          {
            "refId": 4,
            "concept": {
              "unid": 0,
              "shortname": "age"
            },
            "value": "10"
          }
        ],
        "subjectUnid": 1,
        "id": 1
      }
    ],
    "refMap": {
      "0": "age"
    }
  }
}';


        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $this->assertEquals('cat', $entityFactory->entityIsa);

        $verifFactory = new \SandraCore\EntityFactory('cat', 'testFile', $sandra);
        $verifFactory->populateLocal();

        $this->assertCount(2, $entityFactory->getEntities());

        print_r($entityFactory->dumpMeta());


        $this->assertEquals(2, $gossiper->createRefCount);
        $this->assertEquals(1, $gossiper->updateRefCount);
        $this->assertEquals(1, $gossiper->equalRefCount);


    }

    public function testJoined()
    {

        $sandra = TestService::getFlushTestDatagraph();

        $json = '{"gossiper":{"updateOnReferenceShortname":"name"},"entityFactory":{"is_a":"cat","contained_in_file":"testFile","entityArray":[{"id":0,"subjectUnid":3,"referenceArray":[{"refId":0,"concept":{"unid":1,"shortname":"name","triplets":{}},"value":"felix"},{"refId":1,"concept":{"unid":2,"shortname":"age","triplets":{}},"value":"3"}],"triplets":{"hasMaster":[5,6],"friendWith":[4]}},{"id":1,"subjectUnid":4,"referenceArray":[{"refId":2,"concept":{"unid":1,"shortname":"name","triplets":{}},"value":"miaous"},{"refId":3,"concept":{"unid":2,"shortname":"age","triplets":{}},"value":"10"}]}],"refMap":{"1":"name","2":"age"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"name"},"entityFactory":{"is_a":"person","contained_in_file":"peopleFile","entityArray":[{"id":2,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"unid":1,"shortname":"name","triplets":{}},"value":"mike"}]},{"id":3,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"unid":1,"shortname":"name","triplets":{}},"value":"jown"}]}],"refMap":{},"joinedFactory":[]}}]}}
';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        // print_r($gossiper->bufferTripletsArray);


        $verifCatFactory = new \SandraCore\EntityFactory('cat', 'testFile', $sandra);
        $verifOwnerFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $sandra);
        $verifOwnerFactoryFull = new \SandraCore\EntityFactory('person', 'peopleFile', $sandra);
        $verifOwnerFactoryFull->populateLocal();;
        $verifCatFactory->joinFactory('hasMaster', $verifOwnerFactory);
        $verifCatFactory->populateLocal();
        $verifCatFactory->joinPopulate();


        $this->assertCount(2, $verifCatFactory->getEntities());
        $joinedFactory = reset($verifCatFactory->joinedFactoryArray);

        $felix = $verifCatFactory->first('name', 'felix');
        $felixMasters = $felix->getJoinedEntities('hasMaster');

        $this->assertCount(2, $joinedFactory->getEntities(), 'as there is only one cat owner we should have only one person loaded');
        $this->assertCount(2, $felixMasters, 'Felix should have 2 masters');
        $this->assertEquals('mike', $felixMasters[0]->get('name'), 'Felix has Mike as master');
        $this->assertEquals('jown', $felixMasters[1]->get('name'), 'Jown has Mike as master');

    }


}