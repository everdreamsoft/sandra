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
    public $mainEntityPath = '' ;
    public $entityArray = array();
    public $refMap ;
    public $foreignToLocalVocabulary ; //key is foreign value is local


    protected $foreignRawData = '' ;
    protected $flattingArray = array() ;
    protected $foreignRawArray = '' ;

    private $localRefToFuse = null ;
    private $remoteRefFuse = null ;




    public function __construct($url,$pathToItem, System $system){


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

        $this->system = $system ;



       // print_r($this->foreignRawArray);
        //die();




    }
    public function populate($limit = 0){


        //$json = $this->testJson();

        $resultArray = $this->foreignRawArray;

        //die("$this->foreignRawArray do I populate ?$this->mainEntityPath");

        $i=0;

        //path to array
        if (isset ($this->mainEntityPath) && $this->mainEntityPath != ''){
            $pathedArray = $resultArray[$this->mainEntityPath] ;
        }
        else{
            $pathedArray = $resultArray ;
        }

        //if the array is empty return
        if (!isset($pathedArray) or empty($pathedArray)){

            return array();
        }

        //Cycle trough the entity
        foreach ($pathedArray as $key => $foreignEntity){

            $i++;
            $refArray = array();

            if ($limit && $i >= $limit) break ;


            foreach ($foreignEntity as $objectKey => $objectValue){



                //it a flat reference
                if (!is_array($objectValue)){


                    $refArray[$objectKey] =   $objectValue ;

                    //do we have the concept in reference into vocabulary ?
                    if (isset($this->foreignToLocalVocabulary[$objectKey])){
                        //then return local concept
                        $this->refMap[$objectKey] = $this->system->conceptFactory->getConceptFromShortnameOrIdOrCreateShortname($this->foreignToLocalVocabulary[$objectKey]);

                    }
                    else {

                        $this->refMap[$objectKey] = $this->system->conceptFactory->getForeignConceptFromId($objectKey);
                    }

                }
                //has children data
                else {
                    //is the children to be flatten ?
                    if (isset($this->flattingArray[$objectKey])){
                    $flatRef = $this->flatResult($objectValue,$objectKey);
                    $refArray = $refArray + $flatRef ;}



                }

            }

            $entity = new ForeignEntity("foreign$i",$refArray,$this,"foreign$i",$this->system);
            $entityArray["f:$i"] = $entity ;

        }

        $this->entityArray = $entityArray ;

        return $entityArray ;

    }

    public function isLocalMapedReference($refname){


        if (isset($this->foreignToLocalVocabulary[$refname])){

            return $this->foreignToLocalVocabulary[$refname] ;

        }

        else return false ;

    }


    public function fuseRemoteRefEqual( $foreignRef, $localRefConcept){

        $localRefConcept = $this->system->conceptFactory->getConceptFromShortnameOrId($localRefConcept);
        $foreignRefConcept = $this->system->conceptFactory->getForeignConceptFromId($foreignRef);
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
                $this->refMap[$objectKey] = $this->system->conceptFactory->getConceptFromShortnameOrId($this->foreignToLocalVocabulary[$objectKey]);

            }
            else {

                $this->refMap[$objectKey] = $this->system->conceptFactory->getForeignConceptFromId($objectKey);
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