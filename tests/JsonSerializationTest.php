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

    public function testBrotherReference()
    {

        $sandra = TestService::getFlushTestDatagraph();
        $json = '{
  "gossiper": {
    "updateOnReferenceShortname": "assetId",
    "shortNameDictionary": {
      "0": "null_concept",
      "1": "collectionId",
      "2": "assetId",
      "3": "code",
      "4": "class_name",
      "5": "blockchain",
      "6": "address",
      "7": "id",
      "8": "txHash",
      "9": "blockIndex",
      "10": "entity:subject:0",
      "11": "onBlockchain",
      "12": "kusama",
      "13": "entity:subject:1",
      "14": "entity:subject:2",
      "15": "name",
      "16": "imageUrl",
      "17": "description",
      "18": "entity:subject:3",
      "19": "imgUrl",
      "20": "entity:subject:4",
      "21": "contractStandard",
      "22": "bindToCollection",
      "23": "inCollection",
      "24": "source",
      "25": "bindToContract"
    }
  },
  "entityFactory": {
    "is_a": "blockchainizableAsset",
    "contained_in_file": "blockchainizableAssets",
    "entityArray": [
      {
        "id": 3,
        "subjectUnid": 18,
        "referenceArray": [
          {
            "refId": 0,
            "concept": {
              "isPureShortname": false,
              "unid": 2,
              "shortname": "assetId",
              "triplets": {},
              "tripletsReferences": {}
            },
            "value": "A great asset I made"
          },
          {
            "refId": 0,
            "concept": {
              "isPureShortname": false,
              "unid": 19,
              "shortname": "imgUrl",
              "triplets": {},
              "tripletsReferences": {}
            },
            "value": "https://picsum.photos/400"
          },
          {
            "refId": 0,
            "concept": {
              "isPureShortname": false,
              "unid": 17,
              "shortname": "description",
              "triplets": {},
              "tripletsReferences": {}
            },
            "value": "hello"
          }
        ],
        "triplets": {
          "bindToCollection": [
            14
          ],
          "bindToContract": [
            20
          ]
        },
        "tripletsReferences": {
          "bindToContract": [
            {
              "targetUnid": 20,
              "refs": [
                {
                  "conceptUnid": 24,
                  "value": "canonizer"
                }
              ]
            }
          ]
        }
      }
    ],
    "refMap": {},
    "joinedFactory": [
      {
        "gossiper": {
          "updateOnReferenceShortname": "collectionId"
        },
        "entityFactory": {
          "is_a": "assetCollection",
          "contained_in_file": "assetCollectionFile",
          "entityArray": [
            {
              "id": 2,
              "subjectUnid": 14,
              "referenceArray": [
                {
                  "refId": 0,
                  "concept": {
                    "isPureShortname": false,
                    "unid": 1,
                    "shortname": "collectionId",
                    "triplets": {},
                    "tripletsReferences": {}
                  },
                  "value": "myCollection"
                },
                {
                  "refId": 0,
                  "concept": {
                    "isPureShortname": false,
                    "unid": 15,
                    "shortname": "name",
                    "triplets": {},
                    "tripletsReferences": {}
                  },
                  "value": "my veryfirst collection"
                },
                {
                  "refId": 0,
                  "concept": {
                    "isPureShortname": false,
                    "unid": 16,
                    "shortname": "imageUrl",
                    "triplets": {},
                    "tripletsReferences": {}
                  },
                  "value": "https://picsum.photos/400"
                },
                {
                  "refId": 0,
                  "concept": {
                    "isPureShortname": false,
                    "unid": 17,
                    "shortname": "description",
                    "triplets": {},
                    "tripletsReferences": {}
                  },
                  "value": "dolor"
                }
              ]
            }
          ],
          "refMap": {},
          "joinedFactory": []
        }
      },
      {
        "gossiper": {
          "updateOnReferenceShortname": "id"
        },
        "entityFactory": {
          "is_a": "rmrkContract",
          "contained_in_file": "blockchainContractFile",
          "entityArray": [
            {
              "id": 4,
              "subjectUnid": 20,
              "referenceArray": [
                {
                  "refId": 0,
                  "concept": {
                    "isPureShortname": false,
                    "unid": 7,
                    "shortname": "id",
                    "triplets": {},
                    "tripletsReferences": {}
                  },
                  "value": "241B8516516F381A-FRACTAL"
                }
              ],
              "triplets": {
                "contractStandard": [
                  13
                ],
                "inCollection": [
                  14
                ]
              }
            }
          ],
          "refMap": {},
          "joinedFactory": [
            {
              "gossiper": {
                "updateOnReferenceShortname": "class_name"
              },
              "entityFactory": {
                "is_a": "blockchainStandard",
                "contained_in_file": "blockchainStandardFile",
                "entityArray": [
                  {
                    "id": 1,
                    "subjectUnid": 13,
                    "referenceArray": [
                      {
                        "refId": 0,
                        "concept": {
                          "isPureShortname": false,
                          "unid": 4,
                          "shortname": "class_name",
                          "triplets": {},
                          "tripletsReferences": {}
                        },
                        "value": ""
                      }
                    ]
                  }
                ],
                "refMap": {},
                "joinedFactory": []
              }
            },
            {
              "gossiper": {
                "updateOnReferenceShortname": "collectionId"
              },
              "entityFactory": {
                "is_a": "assetCollection",
                "contained_in_file": "assetCollectionFile",
                "entityArray": [
                  {
                    "id": 2,
                    "subjectUnid": 14,
                    "referenceArray": [
                      {
                        "refId": 0,
                        "concept": {
                          "isPureShortname": false,
                          "unid": 1,
                          "shortname": "collectionId",
                          "triplets": {},
                          "tripletsReferences": {}
                        },
                        "value": "myCollection"
                      },
                      {
                        "refId": 0,
                        "concept": {
                          "isPureShortname": false,
                          "unid": 15,
                          "shortname": "name",
                          "triplets": {},
                          "tripletsReferences": {}
                        },
                        "value": "my veryfirst collection"
                      },
                      {
                        "refId": 0,
                        "concept": {
                          "isPureShortname": false,
                          "unid": 16,
                          "shortname": "imageUrl",
                          "triplets": {},
                          "tripletsReferences": {}
                        },
                        "value": "https://picsum.photos/400"
                      },
                      {
                        "refId": 0,
                        "concept": {
                          "isPureShortname": false,
                          "unid": 17,
                          "shortname": "description",
                          "triplets": {},
                          "tripletsReferences": {}
                        },
                        "value": "dolor"
                      }
                    ]
                  }
                ],
                "refMap": {},
                "joinedFactory": []
              }
            }
          ]
        }
      }
    ]
  }
}';


        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $assetFactory = new \SandraCore\EntityFactory('blockchainizableAsset', 'blockchainizableAssets', $sandra);
        $assetFactory->populateLocal();
        $entityAsset = $assetFactory->getEntities();
        $entityAsset = reset($entityAsset);


    }


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

    public function testNullData()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"txHash","shortNameDictionary":{"0":"null_concept","1":"collectionId","2":"assetId","3":"code","4":"class_name","5":"identifier","6":"entity:subject:0","7":"blockchain","8":"address","9":"id","10":"txHash","11":"blockIndex"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[],"refMap":{},"joinedFactory":[]}} ';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $this->assertEquals(0, $gossiper->createRefCount);
        $this->assertEquals(0, $gossiper->rawNewTripletCount);





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


        //the other way arround
        $verifCatFactory->indexShortname = 'name';
        $generatedGossip = $gossiper->exposeGossip($verifCatFactory);

        $this->assertEquals(json_encode($generatedGossip, JSON_PRETTY_PRINT), json_encode(json_decode($json), JSON_PRETTY_PRINT));


    }

    public function testWithShortnameAsLink()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"txId","shortNameDictionary":{"0":"null_concept","1":"address","2":"id","3":"txId","4":"txid","5":"entity:subject:0","6":"entity:subject:1","7":"entity:subject:2","8":"entity:subject:3","9":"timestamp","10":"quantity","11":"source","12":"hasSingleDestination","13":"sourceBlockchainContract","14":"onBlockchain","15":"genericBlockchain"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":4,"shortname":"txid","triplets":{}},"value":"txid1111"},{"refId":0,"concept":{"isPureShortname":false,"unid":9,"shortname":"timestamp","triplets":{}},"value":"1111"},{"refId":0,"concept":{"isPureShortname":false,"unid":10,"shortname":"quantity","triplets":{}},"value":"1"}],"triplets":{"source":[6],"hasSingleDestination":[7],"sourceBlockchainContract":[8],"onBlockchain":[15]}}],"refMap":{"4":"txid"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"address1"}]},{"id":2,"subjectUnid":7,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"addressDest1"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"address1"}]},{"id":2,"subjectUnid":7,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"addressDest1"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"rmrkContractFile","entityArray":[{"id":3,"subjectUnid":8,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"id","triplets":{}},"value":"contract1"}]}],"refMap":{},"joinedFactory":[]}}]}}
';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $blockchainEventFactory = new \SandraCore\EntityFactory('blockchainEvent', 'blockchainEventFile', $sandra);
        $blockchainEventFactory->populateLocal();
        $blockchainEventFactory->getTriplets();

        $entity = $blockchainEventFactory->first('txId', 'txid1111');
        $this->assertInstanceOf(\SandraCore\Entity::class, $entity);
        $entity->hasVerbAndTarget('onBlockchain', 'genericBlockchain');


    }

    public function testWithSubSubFactory()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"txHash","shortNameDictionary":{"0":"null_concept","1":"address","2":"id","3":"txHash","4":"blockIndex","5":"entity:subject:0","6":"entity:subject:1","7":"class_name","8":"entity:subject:2","9":"entity:subject:3","10":"contractStandard","11":"entity:subject:4","12":"timestamp","13":"quantity","14":"source","15":"hasSingleDestination","16":"blockchainContract","17":"entity:subject:5","18":"onBlock","19":"onBlockchain","20":"kusama"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[{"id":4,"subjectUnid":11,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":3,"shortname":"txHash","triplets":{}},"value":"0x6c6520706f7374206d61726368652073616e73206a7175657279"},{"refId":0,"concept":{"isPureShortname":false,"unid":12,"shortname":"timestamp","triplets":{}},"value":"123456"},{"refId":0,"concept":{"isPureShortname":false,"unid":13,"shortname":"quantity","triplets":{}},"value":"1"}],"triplets":{"source":[5],"hasSingleDestination":[6],"blockchainContract":[9],"onBlock":[17],"onBlockchain":[20]}}],"refMap":{"3":"txHash"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"blockchainContractFile","entityArray":[{"id":3,"subjectUnid":9,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"id","triplets":{}},"value":"241B8516516F381A-FRACTAL"}],"triplets":{"contractStandard":[8]}}],"refMap":{},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"class_name"},"entityFactory":{"is_a":"blockchainStandard","contained_in_file":"blockchainStandardFile","entityArray":[{"id":2,"subjectUnid":8,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":7,"shortname":"class_name","triplets":{}},"value":"Interfaces\\\RmrkContractStandard"}]}],"refMap":{},"joinedFactory":[]}}]}},{"gossiper":{"updateOnReferenceShortname":"blockIndex"},"entityFactory":{"is_a":"kusamaBlock","contained_in_file":"blockchainBlocFile","entityArray":[{"id":5,"subjectUnid":17,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":4,"shortname":"blockIndex","triplets":{}},"value":"555"},{"refId":0,"concept":{"isPureShortname":false,"unid":12,"shortname":"timestamp","triplets":{}},"value":"123456"}]}],"refMap":{},"joinedFactory":[]}}]}}
';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $blockchainContractFactory = new \SandraCore\EntityFactory('rmrkContract', 'blockchainContractFile', $sandra);
        $blockchainContractFactory->populateLocal();
        $blockchainContractFactory->getTriplets();

        $blockchainStandardFactory = new \SandraCore\EntityFactory('blockchainStandard', 'blockchainStandardFile', $sandra);
        $blockchainContractFactory->joinFactory('contractStandard', $blockchainStandardFactory);
        $blockchainContractFactory->joinPopulate();

        $entity = $blockchainContractFactory->first('id', '241B8516516F381A-FRACTAL');

        $this->assertCount(1, $entity->getJoinedEntities('contractStandard'));

        $this->assertInstanceOf(\SandraCore\Entity::class, $entity);


    }

    public function testWithReferenceOnTriplet()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"txHash","shortNameDictionary":{"0":"null_concept","1":"address","2":"id","3":"txHash","4":"blockIndex","5":"entity:subject:0","6":"entity:subject:1","7":"class_name","8":"entity:subject:2","9":"entity:subject:3","10":"contractStandard","11":"entity:subject:4","12":"sn","13":"entity:subject:5","14":"timestamp","15":"quantity","16":"source","17":"hasSingleDestination","18":"entity:subject:6","19":"onBlock","20":"onBlockchain","21":"kusama","22":"blockchainContract"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[{"id":5,"subjectUnid":13,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":3,"shortname":"txHash","triplets":{},"tripletsReferences":{}},"value":"0x6c6520706f7374206d61726368652073616e73206a7175657279"},{"refId":0,"concept":{"isPureShortname":false,"unid":14,"shortname":"timestamp","triplets":{},"tripletsReferences":{}},"value":"123456"},{"refId":0,"concept":{"isPureShortname":false,"unid":15,"shortname":"quantity","triplets":{},"tripletsReferences":{}},"value":"1"}],"triplets":{"source":[5],"hasSingleDestination":[6],"onBlock":[18],"onBlockchain":[21],"blockchainContract":[9]},"tripletsReferences":{"blockchainContract":[{"targetUnid":9,"refs":[{"conceptUnid":12,"value":"0000000000000003"}]}]}}],"refMap":{"3":"txHash"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"blockIndex"},"entityFactory":{"is_a":"kusamaBlock","contained_in_file":"blockchainBlocFile","entityArray":[{"id":6,"subjectUnid":18,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":4,"shortname":"blockIndex","triplets":{},"tripletsReferences":{}},"value":"555"},{"refId":0,"concept":{"isPureShortname":false,"unid":14,"shortname":"timestamp","triplets":{},"tripletsReferences":{}},"value":"123456"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"blockchainContractFile","entityArray":[{"id":3,"subjectUnid":9,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"id","triplets":{},"tripletsReferences":{}},"value":"241B8516516F381A-FRACTAL"}],"triplets":{"contractStandard":[8]}}],"refMap":{},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"class_name"},"entityFactory":{"is_a":"blockchainStandard","contained_in_file":"blockchainStandardFile","entityArray":[{"id":2,"subjectUnid":8,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":7,"shortname":"class_name","triplets":{},"tripletsReferences":{}},"value":"CsCannon\\\Blockchains\\\Interfaces\\\RmrkContractStandard"}]}],"refMap":{},"joinedFactory":[]}}]}}]}}
';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $blockchainEventFactory = new \SandraCore\EntityFactory('blockchainEvent', 'blockchainEventFile', $sandra);
        $blockchainEventFactory->populateLocal();
        $blockchainEventFactory->populateBrotherEntities("blockchainContract");
        $blockchainEventFactory->getTriplets();

        $entities = $blockchainEventFactory->getEntities();
        $entity = reset($entities);

        $brotherEntity = $entity->getBrotherEntity("blockchainContract");
        $brotherEntity = reset($brotherEntity);
        $sn = $brotherEntity->get('sn');

        $this->assertInstanceOf(\SandraCore\Entity::class, $brotherEntity);
        $this->assertNotNull($sn);

        $newVal = 5;
        //check update
        $json = '{"gossiper":{"updateOnReferenceShortname":"txHash","shortNameDictionary":{"0":"null_concept","1":"address","2":"id","3":"txHash","4":"blockIndex","5":"entity:subject:0","6":"entity:subject:1","7":"class_name","8":"entity:subject:2","9":"entity:subject:3","10":"contractStandard","11":"entity:subject:4","12":"sn","13":"entity:subject:5","14":"timestamp","15":"quantity","16":"source","17":"hasSingleDestination","18":"entity:subject:6","19":"onBlock","20":"onBlockchain","21":"kusama","22":"blockchainContract"}},"entityFactory":{"is_a":"blockchainEvent","contained_in_file":"blockchainEventFile","entityArray":[{"id":5,"subjectUnid":13,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":3,"shortname":"txHash","triplets":{},"tripletsReferences":{}},"value":"0x6c6520706f7374206d61726368652073616e73206a7175657279"},{"refId":0,"concept":{"isPureShortname":false,"unid":14,"shortname":"timestamp","triplets":{},"tripletsReferences":{}},"value":"123456"},{"refId":0,"concept":{"isPureShortname":false,"unid":15,"shortname":"quantity","triplets":{},"tripletsReferences":{}},"value":"1"}],"triplets":{"source":[5],"hasSingleDestination":[6],"onBlock":[18],"onBlockchain":[21],"blockchainContract":[9]},"tripletsReferences":{"blockchainContract":[{"targetUnid":9,"refs":[{"conceptUnid":12,"value":"' . $newVal . '"}]}]}}],"refMap":{"3":"txHash"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"address"},"entityFactory":{"is_a":"kusamaAddress","contained_in_file":"kusamaAddressFile","entityArray":[{"id":0,"subjectUnid":5,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"DmUVjSi8id22vcH26btyVsVq39p8EVPiepdBEYhzoLL8Qby"}]},{"id":1,"subjectUnid":6,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"address","triplets":{},"tripletsReferences":{}},"value":"GczFVp4yRk8baEBsMZ2JFvFu6n6Ucp1Ry6PV4c3PDNC6pe3"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"blockIndex"},"entityFactory":{"is_a":"kusamaBlock","contained_in_file":"blockchainBlocFile","entityArray":[{"id":6,"subjectUnid":18,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":4,"shortname":"blockIndex","triplets":{},"tripletsReferences":{}},"value":"555"},{"refId":0,"concept":{"isPureShortname":false,"unid":14,"shortname":"timestamp","triplets":{},"tripletsReferences":{}},"value":"123456"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"blockchainContractFile","entityArray":[{"id":3,"subjectUnid":9,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"id","triplets":{},"tripletsReferences":{}},"value":"241B8516516F381A-FRACTAL"}],"triplets":{"contractStandard":[8]}}],"refMap":{},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"class_name"},"entityFactory":{"is_a":"blockchainStandard","contained_in_file":"blockchainStandardFile","entityArray":[{"id":2,"subjectUnid":8,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":7,"shortname":"class_name","triplets":{},"tripletsReferences":{}},"value":"CsCannon\\\Blockchains\\\Interfaces\\\RmrkContractStandard"}]}],"refMap":{},"joinedFactory":[]}}]}}]}}
';
        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $blockchainEventFactory = new \SandraCore\EntityFactory('blockchainEvent', 'blockchainEventFile', $sandra);
        $blockchainEventFactory->populateLocal();
        $blockchainEventFactory->populateBrotherEntities("blockchainContract");
        $blockchainEventFactory->getTriplets();

        $entities = $blockchainEventFactory->getEntities();
        $entity = reset($entities);

        $brotherEntity = $entity->getBrotherEntity("blockchainContract");
        $brotherEntity = reset($brotherEntity);
        $sn = $brotherEntity->get('sn');

        $this->assertInstanceOf(\SandraCore\Entity::class, $brotherEntity);
        $this->assertEquals($newVal, $sn);

    }

    public function testWithEntityAsVerb()
    {
        $sandra = TestService::getFlushTestDatagraph();
        $json = '{"gossiper":{"updateOnReferenceShortname":"code","shortNameDictionary":{"0":"null_concept","1":"collectionId","2":"assetId","3":"code","4":"class_name","5":"address","6":"id","7":"txHash","8":"blockIndex","9":"entity:subject:0","10":"name","11":"imageUrl","12":"description","13":"entity:subject:1","14":"entity:subject:2","15":"bindToCollection","16":"entity:subject:3","17":"sn","18":"entity:subject:4"}},"entityFactory":{"is_a":"tokenPath","contained_in_file":"tokenPathFile","entityArray":[{"id":4,"subjectUnid":18,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":3,"shortname":"code","triplets":{},"tripletsReferences":{}},"value":"sn-0000000004"}],"triplets":{"entity:subject:2":[13]}}],"refMap":{"3":"code"},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"id"},"entityFactory":{"is_a":"rmrkContract","contained_in_file":"blockchainContractFile","entityArray":[{"id":2,"subjectUnid":14,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":6,"shortname":"id","triplets":{},"tripletsReferences":{}},"value":"CONTRACT4TEST"}]}],"refMap":{},"joinedFactory":[]}},{"gossiper":{"updateOnReferenceShortname":"assetId"},"entityFactory":{"is_a":"blockchainizableAsset","contained_in_file":"blockchainizableAssets","entityArray":[{"id":1,"subjectUnid":13,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":2,"shortname":"assetId","triplets":{},"tripletsReferences":{}},"value":"A great asset I made"}],"triplets":{"bindToCollection":[9]}}],"refMap":{},"joinedFactory":[{"gossiper":{"updateOnReferenceShortname":"collectionId"},"entityFactory":{"is_a":"assetCollection","contained_in_file":"assetCollectionFile","entityArray":[{"id":0,"subjectUnid":9,"referenceArray":[{"refId":0,"concept":{"isPureShortname":false,"unid":1,"shortname":"collectionId","triplets":{},"tripletsReferences":{}},"value":"my veryfirst collection"},{"refId":0,"concept":{"isPureShortname":false,"unid":10,"shortname":"name","triplets":{},"tripletsReferences":{}},"value":"my veryfirst collection"},{"refId":0,"concept":{"isPureShortname":false,"unid":11,"shortname":"imageUrl","triplets":{},"tripletsReferences":{}},"value":"https://picsum.photos/400"},{"refId":0,"concept":{"isPureShortname":false,"unid":12,"shortname":"description","triplets":{},"tripletsReferences":{}},"value":"dolor"}]}],"refMap":{},"joinedFactory":[]}}]}}]}}';

        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        $entityFactory = $gossiper->receiveEntityFactory($json);

        $tokenFactory = new \SandraCore\EntityFactory('tokenPath', 'tokenPathFile', $sandra);
        $tokenFactory->populateLocal();
        $tokenFactory->getTriplets();

        $entities = $tokenFactory->getEntities();
        $entity = reset($entities);

        $contractFactory = new \SandraCore\EntityFactory('rmrkContract', 'blockchainContractFile', $sandra);
        $contractFactory->populateLocal();
        $entityContracts = $contractFactory->getEntities();
        $entityContract = reset($entityContracts);

        $assetFactory = new \SandraCore\EntityFactory('blockchainizableAsset', 'blockchainizableAssets', $sandra);
        $assetFactory->populateLocal();
        $entityAsset = $assetFactory->getEntities();
        $entityAsset = reset($entityAsset);

        $this->assertTrue($entity->hasVerbAndTarget($entityContract, $entityAsset));

    }

    public function testExposeGossip()
    {

        $sandra = TestService::getFlushTestDatagraph();
        $catFactory = new \SandraCore\EntityFactory('cat', 'catFile', $sandra);
        $ownerFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $sandra);
        $catFactory->createNew(['name' => 'kitty']);
        $catFactory->createNew(['name' => 'felix']);
        $gossiper = new InnateSkills\Gossiper\Gossiper($sandra);
        print_r($gossiper->exposeGossip($catFactory));

    }


}