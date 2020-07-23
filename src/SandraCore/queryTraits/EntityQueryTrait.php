<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 23.07.20
 * Time: 08:15
 */

namespace SandraCore\queryTraits;


use SandraCore\CommonFunctions;
use SandraCore\Concept;
use SandraCore\Entity;

trait EntityQueryTrait
{


    public function hasTargetConcept($concept): bool
    {

        $system = $this->_getEntity()->system;

        $concept = CommonFunctions::somethingToConcept($concept, $system);

        $this->_getEntity()->factory->getTriplets();
        $map = $this->getTripletTargetMap();

        if (isset($map[$concept->idConcept])) {

            return true;
        }

        return false;

    }

    abstract protected function _getEntity(): Entity;

    private function getTripletTargetMap(): array
    {

        $this->_getEntity()->factory->getTriplets();
        $triplets = $this->_getEntity()->subjectConcept->tripletArray;
        $reverse = [];

        foreach ($triplets ?? array() as $verbKey => $verbs) {
            foreach ($verbs ?? array() as $target) {
                $reverse[$target][$verbKey] = true;

            }
        }

        return $reverse;


    }

    public function hasVerbAndTarget($conceptVerb, $conceptTarget): bool
    {

        $system = $this->_getEntity()->system;

        $conceptVerb = CommonFunctions::somethingToConcept($conceptVerb, $system);
        $conceptTarget = CommonFunctions::somethingToConcept($conceptTarget, $system);

        $this->_getEntity()->factory->getTriplets();
        $map = $this->getTripletTargetMap();

        $this->_getEntity()->factory->getTriplets();
        $triplets = $this->_getEntity()->subjectConcept->tripletArray;

        if (!isset($triplets[$conceptVerb->idConcept])) return false;
        if (!in_array($conceptTarget->idConcept, $triplets[$conceptVerb->idConcept])) return false;


        return true;

    }

    private function TOTOgetTargetConceptsFromVerb($conceptVerb): Concept
    {

        $system = $this->_getEntity()->system;

        $conceptVerb = CommonFunctions::somethingToConcept($conceptVerb, $system);


        $this->_getEntity()->factory->getTriplets();
        $map = $this->getTripletTargetMap();

        $this->_getEntity()->factory->getTriplets();
        $triplets = $this->_getEntity()->subjectConcept->tripletArray;

        if (!isset($triplets[$conceptVerb->idConcept])) return false;


        return true;

    }


}