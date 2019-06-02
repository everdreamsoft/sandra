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
        echo"constructing";
        $this->sandra = $sandra ;

    }

    public function load($parameters)
    {
        echo"loading";

        $idConcept = $parameters['id'];

       $conceptManager = new ConceptManager(1,$this->sandra);
       $result = $conceptManager->getConceptsFromArray(array($idConcept));
       $triplets = $conceptManager->getTriplets();
        $conceptManager->getReferences();
        $concept = $this->sandra->conceptFactory->getConceptFromShortnameOrId($idConcept);
        $concept->tripletArray = $triplets ;


        $concept->getDisplayName();

       print_r($concept->output());

        echo $concept->dumpMeta();




    }

}