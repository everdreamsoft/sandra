<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use PDO;
use SandraCore\Entity;
use SandraCore\QueryExecutor;
use SandraCore\System;

class EmbeddingService
{
    private System $system;
    private string $apiKey;
    private string $model;
    private int $maxTextLength;

    public function __construct(System $system, string $apiKey, string $model = 'text-embedding-3-small', int $maxTextLength = 8000)
    {
        $this->system = $system;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTextLength = $maxTextLength;
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Build a text representation of an entity for embedding.
     * Combines reference key-value pairs, outgoing triplets, and optional storage.
     */
    public function buildEntityText(Entity $entity): string
    {
        $refs = EntitySerializer::extractRefs($entity);
        $parts = [];

        foreach ($refs as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = "$key: $value";
            }
        }

        // Include outgoing triplets (relations) for richer semantic context
        $tripletLines = $this->getEntityTripletText((int)$entity->subjectConcept->idConcept);
        if (!empty($tripletLines)) {
            $parts[] = "--- relations ---";
            foreach ($tripletLines as $line) {
                $parts[] = $line;
            }
        }

        $text = implode("\n", $parts);

        $storage = $entity->getStorage();
        if ($storage !== null && $storage !== '') {
            $text .= "\n---\n" . $storage;
        }

        if (mb_strlen($text) > $this->maxTextLength) {
            $text = mb_substr($text, 0, $this->maxTextLength);
        }

        return $text;
    }

    /**
     * Get human-readable outgoing triplet lines for an entity.
     * Excludes internal structural triplets (is_a, contained_in_file).
     * Resolves entity targets to their "name" ref when shortname is NULL.
     *
     * @return string[]
     */
    private function getEntityTripletText(int $conceptId): array
    {
        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $conceptTable = $this->system->conceptTable;
        $deletedId = (int)$this->system->deletedUNID;

        $sql = "SELECT cl.shortname AS verb,
                       ct.shortname AS target,
                       l.idConceptTarget AS targetId
                FROM `{$linkTable}` l
                LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                WHERE l.idConceptStart = :conceptId
                  AND l.flag != :deleted
                LIMIT 50";

        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':conceptId' => [$conceptId, PDO::PARAM_INT],
            ':deleted' => [$deletedId, PDO::PARAM_INT],
        ]);

        // Skip structural verbs that don't add semantic value
        $skipVerbs = ['is_a', 'contained_in_file', 'containedIn', 'is_a_file'];
        $lines = [];

        foreach ($rows as $row) {
            $verb = $row['verb'] ?? '';
            if ($verb === '' || in_array($verb, $skipVerbs, true)) {
                continue;
            }

            $target = $row['target'] ?? '';

            // If shortname is null/empty, resolve entity target name via its refs
            if ($target === '') {
                $target = $this->resolveEntityName((int)$row['targetId']);
            }
            if ($target === '') {
                continue;
            }

            $lines[] = "$verb: $target";
        }

        return $lines;
    }

    /**
     * Look up the "name" reference value for an entity concept.
     * Uses the Sandra triplet+reference structure: entity -> is_a link has refs.
     */
    private function resolveEntityName(int $conceptId): string
    {
        $pdo = $this->system->getConnection();
        $linkTable = $this->system->linkTable;
        $refTable = $this->system->tableReference;
        $conceptTable = $this->system->conceptTable;

        // Get the concept ID for the "name" ref key
        $nameConceptId = $this->system->systemConcept->get('name', null, false);
        if ($nameConceptId === null) {
            return '';
        }

        // Entity refs are stored on the is_a triplet link (linkId).
        // Find the is_a link for this entity, then get the "name" ref on it.
        $isaId = $this->system->systemConcept->get('is_a');

        $sql = "SELECT r.value
                FROM `{$linkTable}` l
                JOIN `{$refTable}` r ON r.idConcept = l.id
                JOIN `{$conceptTable}` c ON c.id = r.linkReferenced
                WHERE l.idConceptStart = :conceptId
                  AND l.idConceptLink = :isaId
                  AND c.shortname = 'name'
                LIMIT 1";

        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':conceptId' => [$conceptId, PDO::PARAM_INT],
            ':isaId' => [$isaId, PDO::PARAM_INT],
        ]);

        return $rows ? $rows[0]['value'] : '';
    }

    /**
     * Call OpenAI embeddings API.
     *
     * @return float[] Vector of floats (1536 dimensions for text-embedding-3-small)
     * @throws \RuntimeException on API error
     */
    public function getEmbedding(string $text): array
    {
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'input' => $text,
                'model' => $this->model,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Embedding API curl error: $curlError");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Embedding API returned HTTP $httpCode: $response");
        }

        $data = json_decode($response, true);
        if (!isset($data['data'][0]['embedding'])) {
            throw new \RuntimeException("Unexpected embedding API response structure");
        }

        return $data['data'][0]['embedding'];
    }

    /**
     * Store an embedding vector in the database (upsert).
     */
    public function storeEmbedding(int $conceptId, array $embedding, string $textHash): void
    {
        $table = $this->system->tableEmbedding;
        $pdo = $this->system->getConnection();

        $sql = "INSERT INTO `$table` (conceptId, embedding, textHash)
                VALUES (:conceptId, :embedding, :textHash)
                ON DUPLICATE KEY UPDATE embedding = :embedding2, textHash = :textHash2";

        $embeddingJson = json_encode($embedding);

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':conceptId' => $conceptId,
            ':embedding' => $embeddingJson,
            ':textHash' => $textHash,
            ':embedding2' => $embeddingJson,
            ':textHash2' => $textHash,
        ]);
    }

    /**
     * Get the stored text hash for an entity to check if re-embedding is needed.
     */
    public function getTextHash(int $conceptId): ?string
    {
        $table = $this->system->tableEmbedding;
        $pdo = $this->system->getConnection();

        $sql = "SELECT textHash FROM `$table` WHERE conceptId = :conceptId LIMIT 1";
        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':conceptId' => [$conceptId, PDO::PARAM_INT],
        ]);

        return $rows ? $rows[0]['textHash'] : null;
    }

    /**
     * Embed an entity: build text, check hash, call API if needed, store.
     */
    public function embedEntity(Entity $entity): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $text = $this->buildEntityText($entity);
        if (trim($text) === '') {
            return;
        }

        $conceptId = (int)$entity->subjectConcept->idConcept;
        $hash = hash('sha256', $text);

        $existingHash = $this->getTextHash($conceptId);
        if ($existingHash === $hash) {
            return;
        }

        $embedding = $this->getEmbedding($text);
        $this->storeEmbedding($conceptId, $embedding, $hash);
    }

    /**
     * Compute cosine similarity between two vectors.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Search for entities similar to a query text.
     *
     * @return array<array{conceptId: int, similarity: float}>
     */
    public function searchSimilar(string $queryText, int $limit = 10, ?string $factoryFilter = null): array
    {
        $queryEmbedding = $this->getEmbedding($queryText);

        $table = $this->system->tableEmbedding;
        $pdo = $this->system->getConnection();

        if ($factoryFilter !== null) {
            $isaConceptId = $this->system->systemConcept->get('is_a');
            $factoryConceptId = $this->system->systemConcept->get($factoryFilter, null, false);

            if ($factoryConceptId === null) {
                return [];
            }

            $linkTable = $this->system->linkTable;
            $deletedId = $this->system->deletedUNID;

            $sql = "SELECT e.conceptId, e.embedding
                    FROM `$table` e
                    JOIN `$linkTable` t ON t.idConceptStart = e.conceptId
                    WHERE t.idConceptLink = :isaId
                      AND t.idConceptTarget = :factoryId
                      AND t.flag != :deletedFlag";

            $rows = QueryExecutor::fetchAll($pdo, $sql, [
                ':isaId' => [$isaConceptId, PDO::PARAM_INT],
                ':factoryId' => [$factoryConceptId, PDO::PARAM_INT],
                ':deletedFlag' => [$deletedId, PDO::PARAM_INT],
            ]);
        } else {
            $sql = "SELECT conceptId, embedding FROM `$table`";
            $rows = QueryExecutor::fetchAll($pdo, $sql, []);
        }

        if (empty($rows)) {
            error_log("[sandra-embed] searchSimilar: 0 rows in embeddings table for query: " . substr($queryText, 0, 60));
            return [];
        }

        $scored = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $storedEmbedding = json_decode($row['embedding'], true);
            if (!is_array($storedEmbedding)) {
                $skipped++;
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);
            $scored[] = [
                'conceptId' => (int)$row['conceptId'],
                'similarity' => round($similarity, 4),
            ];
        }

        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        $topScore = !empty($scored) ? $scored[0]['similarity'] : 0;
        error_log("[sandra-embed] searchSimilar: " . count($rows) . " rows, $skipped skipped, top score=$topScore, query=" . substr($queryText, 0, 60));

        return array_slice($scored, 0, $limit);
    }
}
