<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 17:18
 */

namespace SandraCore;
use Exception;


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


    public $foreignRawData = '';
    protected $flattingArray = array() ;
    public $foreignRawArray = '';

    private $localRefToFuse = null ;
    private $remoteRefFuse = null ;




    public function __construct($url,$pathToItem, System $system){


        //$json = $this->testJson();
        if (is_null($url)) return ;

        $this->mainEntityPath = "$pathToItem";

        try {
            $ch = curl_init();

            // Check if initialization had gone wrong*
            if ($ch === false) {
                throw new Exception('failed to initialize');
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);


            $json = curl_exec($ch);

            // Check the return value of curl_exec(), too
            if ($json === false) {
                throw new Exception(curl_error($ch), curl_errno($ch));
            }

            /* Process $content here */

            // Close curl handle
            curl_close($ch);
        } catch(Exception $e) {

            throw new Exception($e);



        }



        $this->foreignRawArray = json_decode($json,1);

        $this->system = $system ;



        //echo" X $json $url";



        // print_r($this->foreignRawArray);
        //die();




    }
    public function populate($limit = 0){


        //$json = $this->testJson();

        $resultArray = $this->foreignRawArray;

        //die("$this->foreignRawArray do I populate ?$this->mainEntityPath");

        $i=0;

        $pathedArray = $this->divideForeignPath($resultArray,$this->mainEntityPath);


        //if the array is empty return
        if (!isset($pathedArray) or empty($pathedArray)){

            return array();
        }

        //Cycle trough the entity
        foreach ($pathedArray as $key => $foreignEntity){

            $i++;
            $refArray = array();

            if ($limit && $i > $limit) break;

            //fix if there is only one entity
            if (!is_array($foreignEntity)) $foreignEntity = [$foreignEntity];


            foreach ($foreignEntity as $objectKey => $objectValue) {


                //it a flat reference
                if (!is_array($objectValue)) {


                    $refArray[$objectKey] =   $objectValue ;

                    //do we have the concept in reference into vocabulary ?
                    if (isset($this->foreignToLocalVocabulary[$objectKey])){

                        //then return local concept
                        //$this->refMap[$objectKey] = $objectValue ;

                    }
                    else {

                        //  $this->refMap[$objectKey] = $objectValue;
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
        $this->populated = true ;

        return $entityArray ;

    }

    public function divideForeignPath($pathedArray,$path){

        if (empty($path)) {

            return $pathedArray ;
        }

        $explodedPath = explode ('/',$path);
        $fistNode = reset($explodedPath);

        //If we have a special command in the path for example first or last node
        switch($fistNode){

            case '$first':
                $pathedArray = reset($pathedArray);
                break ;

            case '$last':
                $pathedArray = end($pathedArray);
                break ;

            default:
                $pathedArray = $pathedArray[$fistNode];


        }



        //remove first node
        array_shift($explodedPath);

        return $this->divideForeignPath($pathedArray,implode('/',$explodedPath)) ;




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

            $refArray["$prefix.".$objectKey] =   $objectValue ;

            if (isset($this->foreignToLocalVocabulary[$objectKey])){
                //then return local concept
                $this->refMap[$objectKey] = $this->system->conceptFactory->getConceptFromShortnameOrId($this->foreignToLocalVocabulary[$objectKey]);

            }
            else {

                $this->refMap["$prefix.".$objectKey] = $this->system->conceptFactory->getForeignConceptFromId($objectKey);
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