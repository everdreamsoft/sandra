<?php
declare(strict_types=1);

namespace SandraCore\Graph;

use SandraCore\CommonFunctions;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\System;

class GraphTraverser
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    public function bfs(Entity $start, string $verb, int $maxDepth = 10): TraversalResult
    {
        $result = new TraversalResult();
        $verbId = CommonFunctions::somethingToConceptId($verb, $this->system);
        $factory = $start->factory;

        $visited = [];
        $queue = [[$start, 0]];
        $visited[$start->subjectConcept->idConcept] = true;

        while (!empty($queue)) {
            [$current, $depth] = array_shift($queue);

            if ($depth > 0) {
                $result->addEntity($current, $depth);
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $neighbors = $this->getForwardNeighbors($current, $verbId, $factory);
            foreach ($neighbors as $neighbor) {
                $nId = $neighbor->subjectConcept->idConcept;
                if (isset($visited[$nId])) {
                    $result->markCycle();
                    continue;
                }
                $visited[$nId] = true;
                $queue[] = [$neighbor, $depth + 1];
            }
        }

        return $result;
    }

    public function dfs(Entity $start, string $verb, int $maxDepth = 10): TraversalResult
    {
        $result = new TraversalResult();
        $verbId = CommonFunctions::somethingToConceptId($verb, $this->system);
        $factory = $start->factory;

        $visited = [];
        $this->dfsRecursive($start, $verbId, $factory, 0, $maxDepth, $visited, $result);

        return $result;
    }

    private function dfsRecursive(Entity $current, $verbId, EntityFactory $factory, int $depth, int $maxDepth, array &$visited, TraversalResult $result): void
    {
        $cId = $current->subjectConcept->idConcept;
        $visited[$cId] = true;

        if ($depth > 0) {
            $result->addEntity($current, $depth);
        }

        if ($depth >= $maxDepth) {
            return;
        }

        $neighbors = $this->getForwardNeighbors($current, $verbId, $factory);
        foreach ($neighbors as $neighbor) {
            $nId = $neighbor->subjectConcept->idConcept;
            if (isset($visited[$nId])) {
                $result->markCycle();
                continue;
            }
            $this->dfsRecursive($neighbor, $verbId, $factory, $depth + 1, $maxDepth, $visited, $result);
        }
    }

    public function descendants(Entity $entity, string $verb, int $maxDepth = 10): TraversalResult
    {
        return $this->bfs($entity, $verb, $maxDepth);
    }

    public function ancestors(Entity $entity, string $verb, int $maxDepth = 10): TraversalResult
    {
        $result = new TraversalResult();
        $verbId = CommonFunctions::somethingToConceptId($verb, $this->system);
        $factory = $entity->factory;

        $reverseIndex = $this->buildReverseIndex($verbId, $factory);

        $visited = [];
        $queue = [[$entity, 0]];
        $visited[$entity->subjectConcept->idConcept] = true;

        while (!empty($queue)) {
            [$current, $depth] = array_shift($queue);

            if ($depth > 0) {
                $result->addEntity($current, $depth);
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $cId = $current->subjectConcept->idConcept;
            $parents = $reverseIndex[$cId] ?? [];
            foreach ($parents as $parent) {
                $pId = $parent->subjectConcept->idConcept;
                if (isset($visited[$pId])) {
                    $result->markCycle();
                    continue;
                }
                $visited[$pId] = true;
                $queue[] = [$parent, $depth + 1];
            }
        }

        return $result;
    }

    public function hasCycle(Entity $entity, string $verb, int $maxDepth = 100): bool
    {
        $verbId = CommonFunctions::somethingToConceptId($verb, $this->system);
        $factory = $entity->factory;

        $visited = [];
        $stack = [];
        return $this->hasCycleRecursive($entity, $verbId, $factory, $visited, $stack, 0, $maxDepth);
    }

    private function hasCycleRecursive(Entity $current, $verbId, EntityFactory $factory, array &$visited, array &$stack, int $depth, int $maxDepth): bool
    {
        $cId = $current->subjectConcept->idConcept;
        $visited[$cId] = true;
        $stack[$cId] = true;

        if ($depth >= $maxDepth) {
            return false;
        }

        $neighbors = $this->getForwardNeighbors($current, $verbId, $factory);
        foreach ($neighbors as $neighbor) {
            $nId = $neighbor->subjectConcept->idConcept;
            if (isset($stack[$nId])) {
                return true;
            }
            if (!isset($visited[$nId])) {
                if ($this->hasCycleRecursive($neighbor, $verbId, $factory, $visited, $stack, $depth + 1, $maxDepth)) {
                    return true;
                }
            }
        }

        unset($stack[$cId]);
        return false;
    }

    /** @return Path[] */
    public function findPaths(Entity $from, Entity $to, array $verbs, int $maxDepth = 5): array
    {
        $verbIds = [];
        foreach ($verbs as $verb) {
            $verbIds[] = CommonFunctions::somethingToConceptId($verb, $this->system);
        }
        $factory = $from->factory;
        $toId = $to->subjectConcept->idConcept;

        $paths = [];
        $initialPath = new Path([$from]);
        $this->findPathsDfs($from, $toId, $verbIds, $factory, $initialPath, 0, $maxDepth, $paths);

        return $paths;
    }

    private function findPathsDfs(Entity $current, $toId, array $verbIds, EntityFactory $factory, Path $currentPath, int $depth, int $maxDepth, array &$paths): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        foreach ($verbIds as $verbId) {
            $neighbors = $this->getForwardNeighbors($current, $verbId, $factory);
            foreach ($neighbors as $neighbor) {
                if ($currentPath->contains($neighbor)) {
                    continue;
                }

                $newPath = $currentPath->append($neighbor);
                $nId = $neighbor->subjectConcept->idConcept;

                if ($nId == $toId) {
                    $paths[] = $newPath;
                } else {
                    $this->findPathsDfs($neighbor, $toId, $verbIds, $factory, $newPath, $depth + 1, $maxDepth, $paths);
                }
            }
        }
    }

    public function shortestPath(Entity $from, Entity $to, array $verbs): ?Path
    {
        $verbIds = [];
        foreach ($verbs as $verb) {
            $verbIds[] = CommonFunctions::somethingToConceptId($verb, $this->system);
        }
        $factory = $from->factory;
        $toId = $to->subjectConcept->idConcept;
        $fromId = $from->subjectConcept->idConcept;

        if ($fromId == $toId) {
            return new Path([$from]);
        }

        $visited = [$fromId => true];
        $queue = [[new Path([$from]), $from]];

        while (!empty($queue)) {
            [$currentPath, $current] = array_shift($queue);

            foreach ($verbIds as $verbId) {
                $neighbors = $this->getForwardNeighbors($current, $verbId, $factory);
                foreach ($neighbors as $neighbor) {
                    $nId = $neighbor->subjectConcept->idConcept;
                    if (isset($visited[$nId])) {
                        continue;
                    }
                    $visited[$nId] = true;
                    $newPath = $currentPath->append($neighbor);

                    if ($nId == $toId) {
                        return $newPath;
                    }

                    $queue[] = [$newPath, $neighbor];
                }
            }
        }

        return null;
    }

    /** @return Entity[] */
    private function getForwardNeighbors(Entity $entity, $verbId, EntityFactory $factory): array
    {
        $neighbors = [];
        $triplets = $entity->subjectConcept->tripletArray ?? [];

        if (!isset($triplets[$verbId])) {
            return $neighbors;
        }

        foreach ($triplets[$verbId] as $targetConceptId) {
            if (isset($factory->entityArray[$targetConceptId])) {
                $neighbors[] = $factory->entityArray[$targetConceptId];
            }
        }

        return $neighbors;
    }

    /**
     * Build a reverse index: targetConceptId => [Entity sources]
     * @return array<int, Entity[]>
     */
    private function buildReverseIndex($verbId, EntityFactory $factory): array
    {
        $reverse = [];
        foreach ($factory->entityArray as $conceptId => $entity) {
            $triplets = $entity->subjectConcept->tripletArray ?? [];
            if (isset($triplets[$verbId])) {
                foreach ($triplets[$verbId] as $targetConceptId) {
                    $reverse[$targetConceptId][] = $entity;
                }
            }
        }
        return $reverse;
    }
}
