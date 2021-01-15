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


    public function testWithRrmrk()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"txId","shortNameDictionary":{"0":"null_concept","1":"address","2":"id","3":"txId","4":"txid","5":"entity:subject:0","6":"entity:subject:1","7":"entity:subject:2","8":"entity:subject:3","9":"timestamp","10":"quantity","11":"source","12":"hasSingleDestination","13":"sourceBlockchainContract","14":"onBlockchain","15":"genericBlockchain"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":4,"shortname":"txid","triplets":{}},"value":"txid1111"},{"refId":0,"concept":{"isPureShortname":false,"unid":9,"shortname":"timestamp","triplets":{}},"value":"1111"},{"refId":0,"concept":{"isPureShortname":false,"unid":10,"shortname":"quantity","triplets":{}},"value":"1"}],"triplets":{"source":[6],"hasSingleDestination":[7],"sourceBlockchainContract":[8],"onBlockchain":[15]}}],"refMap":{"4":"txid"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"address1"}]},{"id":2,"subjectUnid":7,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"addressDest1"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"address1"}]},{"id":2,"subjectUnid":7,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"addressDest1"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"rmrkContractFile","entityArray":[{"id":3,"subjectUnid":8,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"id","triplets":{}},"value":"contract1"}]}],"refMap":{},"joinedFactory":[]}}]}}
';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);


        $blockchainEventFactory = new \SandraCore\EntityFactory('blockchainEvent', 'blockchainEventFile', $sandra);
        $blockchainEventFactory->populateLocal();
        $blockchainEventFactory->getTriplets();
        // $blockchainEventFactory->getEntities()[0] ;
        print_r($blockchainEventFactory->dumpMeta());

        die();


    }/*

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


*/


}