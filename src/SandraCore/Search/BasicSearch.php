<?php
declare(strict_types=1);

namespace SandraCore\Search;

use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Reference;

/**
 * Basic in-memory full-text search.
 * Compatible with all databases. Filters post-chargement on references.
 * Factory must be populated before search.
 */
class BasicSearch implements SearchInterface
{
    public function search(EntityFactory $factory, string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $words = preg_split('/\s+/', mb_strtolower($query));
        $scored = [];

        foreach ($factory->getEntities() as $entity) {
            if (!is_array($entity->entityRefs)) {
                continue;
            }
            $score = $this->scoreEntity($entity, $words, null);
            if ($score > 0) {
                $scored[] = ['entity' => $entity, 'score' => $score];
            }
        }

        // Sort by score descending (higher = better match)
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $results = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            $results[] = $item['entity'];
        }

        return $results;
    }

    public function searchByField(EntityFactory $factory, string $field, string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $words = preg_split('/\s+/', mb_strtolower($query));
        $scored = [];

        foreach ($factory->getEntities() as $entity) {
            if (!is_array($entity->entityRefs)) {
                continue;
            }
            $score = $this->scoreEntity($entity, $words, $field);
            if ($score > 0) {
                $scored[] = ['entity' => $entity, 'score' => $score];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $results = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            $results[] = $item['entity'];
        }

        return $results;
    }

    /**
     * Score an entity against search words.
     * All words must match (AND logic).
     * Scoring: exact match = 3, starts with = 2, contains = 1.
     */
    private function scoreEntity(Entity $entity, array $words, ?string $field): int
    {
        $totalScore = 0;

        foreach ($words as $word) {
            $wordScore = $this->scoreWord($entity, $word, $field);
            if ($wordScore === 0) {
                return 0; // AND logic: all words must match
            }
            $totalScore += $wordScore;
        }

        return $totalScore;
    }

    /**
     * Score a single word against entity references.
     */
    private function scoreWord(Entity $entity, string $word, ?string $field): int
    {
        $bestScore = 0;

        foreach ($entity->entityRefs as $ref) {
            if (!($ref instanceof Reference)) {
                continue;
            }

            // If a specific field is requested, only match that field
            if ($field !== null) {
                $refName = $ref->refConcept->getDisplayName();
                if ($refName !== $field) {
                    continue;
                }
            }

            $value = mb_strtolower((string)$ref->refValue);
            if ($value === '') {
                continue;
            }

            // Exact match (entire value)
            if ($value === $word) {
                $bestScore = max($bestScore, 3);
                continue;
            }

            // Check each word in the value
            $valueWords = preg_split('/\s+/', $value);
            foreach ($valueWords as $vw) {
                if ($vw === $word) {
                    $bestScore = max($bestScore, 3);
                } elseif (mb_strpos($vw, $word) === 0) {
                    $bestScore = max($bestScore, 2); // starts with
                } elseif (mb_strpos($vw, $word) !== false) {
                    $bestScore = max($bestScore, 1); // contains
                }
            }

            // Also check if word appears anywhere in the full value
            if ($bestScore === 0 && mb_strpos($value, $word) !== false) {
                $bestScore = 1;
            }
        }

        return $bestScore;
    }
}
