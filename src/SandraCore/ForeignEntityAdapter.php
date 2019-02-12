<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 17:18
 */

namespace SandraCore;



/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 16.01.19
 * Time: 18:22
 */
class ForeignEntityAdapter extends EntityFactory
{
    public $mainEntityPath = 'txs' ;
    public $entityArray = array();
    public $refMap ;
    public $foreignToLocalVocabulary ; //key is foreign value is local


    protected $foreignRawData = '' ;
    protected $flattingArray = array() ;
    protected $foreignRawArray = '' ;

    private $localRefToFuse = null ;
    private $remoteRefFuse = null ;




    public function __construct($url,$pathToItem){


        //$json = $this->testJson();

        $this->mainEntityPath = "$pathToItem";

        // create curl resource
        $ch = curl_init();

        // set url
        curl_setopt($ch, CURLOPT_URL, "$url");

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // $output contains the output string
        $json = curl_exec($ch);

        // close curl resource to free up system resources
        curl_close($ch);


        $this->foreignRawArray = json_decode($json,1);



        //print_r($this->foreignRawArray);
        //die();




    }
    public function populate(){


        //$json = $this->testJson();

        $resultArray = $this->foreignRawArray;

        //die("$this->foreignRawArray do I populate ?$this->mainEntityPath");

        $i=0;

        //Cycle trough the entity
        foreach ($resultArray[$this->mainEntityPath] as $key => $foreignEntity){

            $i++;
            $refArray = array();


            foreach ($foreignEntity as $objectKey => $objectValue){



                //it a flat reference
                if (!is_array($objectValue)){


                    $refArray[$objectKey] =   $objectValue ;

                    //do we have the concept in reference into vocabulary ?
                    if ($this->foreignToLocalVocabulary[$objectKey]){
                        //then return local concept
                        $this->refMap[$objectKey] = ConceptFactory::getConceptFromShortnameOrId($this->foreignToLocalVocabulary[$objectKey]);

                    }
                    else {

                        $this->refMap[$objectKey] = ConceptFactory::getForeignConceptFromId($objectKey);
                    }

                }
                //has children data
                else {
                    //is the children to be flatten ?
                    if ($this->flattingArray[$objectKey]);
                    $flatRef = $this->flatResult($objectValue,$objectKey);
                    $refArray = $refArray + $flatRef ;



                }

            }

            $entity = new SandraForeignEntity("foreign$i",$refArray,$this,"foreign$i");
            $entityArray["f:$i"] = $entity ;

        }

        $this->entityArray = $entityArray ;

        return $entityArray ;

    }

    public function isLocalMapedReference($refname){


        if ($this->foreignToLocalVocabulary[$refname]){

            return $this->foreignToLocalVocabulary[$refname] ;

        }

        else return false ;

    }


    public function fuseRemoteRefEqual( $foreignRef, $localRefConcept){

        $localRefConcept = ConceptFactory::getConceptFromShortnameOrId($localRefConcept);
        $foreignRefConcept = ConceptFactory::getForeignConceptFromId($foreignRef);
        $this->remoteRefFuse = $foreignRefConcept ;
        $this->localRefToFuse =  $localRefConcept ;

        //foreach ()


    }




    public function returnEntities(){



        return $this->entityArray ;

    }

    public function adaptToLocalVocabulary($vocabularyArray){

        //The idea is foreign API have different wording than local concep. We need to agree on the vocabulary to map
        //concepts

        $this->foreignToLocalVocabulary = $vocabularyArray ;

    }

    public function addToLocalVocabulary($foreign, $localName){

        //The idea is foreign API have different wording than local concep. We need to agree on the vocabulary to map
        //concepts

        $this->foreignToLocalVocabulary[$foreign] = $localName ;

    }


    public function getReferenceMap(){


        return $this->refMap ;

    }


    /**
     * Flat sub entity mean we move one move the parameter data one level up
     *
     * @return mixed
     */
    public function flatSubEntity($pathToFlat,$prefix){


        $this->flattingArray[$pathToFlat] = $prefix ;

    }

    private function flatResult($dataToFlat,$path){

        $prefix = $this->flattingArray[$path];

        foreach ($dataToFlat as $objectKey => $objectValue) {
            //do we have the concept in reference into vocabulary ?

            $refArray[$objectKey] =   $objectValue ;

            if ($this->foreignToLocalVocabulary[$objectKey]){
                //then return local concept
                $this->refMap[$objectKey] = ConceptFactory::getConceptFromShortnameOrId($this->foreignToLocalVocabulary[$objectKey]);

            }
            else {

                $this->refMap[$objectKey] = ConceptFactory::getForeignConceptFromId($objectKey);
            }


        }

        return $refArray ;

    }


    public function saveAllLocally(SandraEntityFactory $factory){

        foreach ($this->entityArray as $key => $entity){



            //$entity->save($factory);


        }




    }





}