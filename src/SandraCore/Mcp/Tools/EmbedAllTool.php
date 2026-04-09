<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * Backfill embeddings for all existing entities that don't have one yet.
 * Run this once after enabling embeddings to index existing data.
 */
class EmbedAllTool implements McpToolInterface
{
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private System $system;
    private EmbeddingService $embeddingService;

    public function __construct(array &$factories, System $system, EmbeddingService $embeddingService)
    {
        $this->factories = &$factories;
        $this->system = $system;
        $this->embeddingService = $embeddingService;
    }

    public function name(): string
    {
        return 'sandra_embed_all';
    }

    public function description(): string
    {
        return 'Backfill embeddings for existing entities that don\'t have one yet. '
            . 'Run once after enabling embeddings to index all existing data. '
            . 'Optionally filter by factory. Skips entities that already have an up-to-date embedding.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'Optional: only embed entities in this factory. If omitted, embeds all factories.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max entities to process (default 100). Use to control API costs.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $factoryFilter = $args['factory'] ?? null;
        $limit = (int)($args['limit'] ?? 100);

        $stats = [
            'embedded' => 0,
            'skipped' => 0,
            'errors' => 0,
            'factories_processed' => [],
        ];

        $factoriesToProcess = [];
        if ($factoryFilter !== null) {
            if (!isset($this->factories[$factoryFilter])) {
                throw new \InvalidArgumentException("Unknown factory: $factoryFilter");
            }
            $factoriesToProcess[$factoryFilter] = $this->factories[$factoryFilter];
        } else {
            $factoriesToProcess = $this->factories;
        }

        $processed = 0;

        foreach ($factoriesToProcess as $name => $entry) {
            if ($processed >= $limit) {
                break;
            }

            $factory = $entry['factory'];
            $loadFactory = new EntityFactory(
                $factory->entityIsa,
                $factory->entityContainedIn,
                $this->system
            );

            $remaining = $limit - $processed;
            $loadFactory->populateLocal($remaining, 0, 'ASC');
            $entities = $loadFactory->getEntities();

            $factoryEmbedded = 0;
            $factorySkipped = 0;
            $factoryErrors = 0;

            foreach ($entities as $entity) {
                if ($processed >= $limit) {
                    break;
                }

                try {
                    $text = $this->embeddingService->buildEntityText($entity);
                    if (trim($text) === '') {
                        $factorySkipped++;
                        continue;
                    }

                    $conceptId = (int)$entity->subjectConcept->idConcept;
                    $hash = hash('sha256', $text);
                    $existingHash = $this->embeddingService->getTextHash($conceptId);

                    if ($existingHash === $hash) {
                        $factorySkipped++;
                        continue;
                    }

                    $this->embeddingService->embedEntity($entity);
                    $factoryEmbedded++;
                    $processed++;
                } catch (\Throwable $e) {
                    $factoryErrors++;
                    $processed++;
                }
            }

            $stats['factories_processed'][] = [
                'factory' => $name,
                'embedded' => $factoryEmbedded,
                'skipped' => $factorySkipped,
                'errors' => $factoryErrors,
            ];

            $stats['embedded'] += $factoryEmbedded;
            $stats['skipped'] += $factorySkipped;
            $stats['errors'] += $factoryErrors;
        }

        return $stats;
    }
}
