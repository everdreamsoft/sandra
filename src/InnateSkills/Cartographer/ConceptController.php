<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 02.06.2019
 * Time: 17:30
 */

namespace InnateSkills\Cartographer;


use SandraCore\ConceptManager;
use SandraCore\System;

class ConceptController
{

    private $sandra = null ;

    public function __construct(System $sandra)
    {

        $this->sandra = $sandra ;

    }

    public function load($parameters)
    {


        $idConcept = $parameters['id'];

       $conceptManager = new ConceptManager(1,$this->sandra);
       $result = $conceptManager->getConceptsFromArray(array($idConcept));
       $triplets = $conceptManager->getTriplets();



        $concept = $this->sandra->conceptFactory->getConceptFromShortnameOrId($idConcept);
        $concept->getReferences(1);
        $concept->tripletArray = $triplets ;


        $concept->getDisplayName();

       print_r($concept->output());

        echo $concept->dumpMeta();




    }

}