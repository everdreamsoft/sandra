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

        $system = $this->getEntity()->system;

        $concept = CommonFunctions::somethingToConcept($concept, $system);

        $this->getEntity()->factory->getTriplets();
        $map = $this->getTripletTargetMap();

        if (isset($map[$concept->idConcept])) {

            return true;
        }

        return false;

    }

    abstract protected function getEntity(): Entity;

    private function getTripletTargetMap(): array
    {

        $this->getEntity()->factory->getTriplets();
        $triplets = $this->getEntity()->subjectConcept->tripletArray;
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

        $system = $this->getEntity()->system;

        $conceptVerb = CommonFunctions::somethingToConcept($conceptVerb, $system);
        $conceptTarget = CommonFunctions::somethingToConcept($conceptTarget, $system);

        $this->getEntity()->factory->getTriplets();
        $map = $this->getTripletTargetMap();

        $this->getEntity()->factory->getTriplets();
        $triplets = $this->getEntity()->subjectConcept->tripletArray;

        if (!isset($triplets[$conceptVerb->idConcept])) return false;
        if (!in_array($conceptTarget->idConcept, $triplets[$conceptVerb->idConcept])) return false;


        return true;

    }


}