<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use PDO;
use SandraCore\Entity;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\QueryExecutor;
use SandraCore\System;

/**
 * MCP tool `sandra_traverse` — walk the graph from an entity along one verb.
 *
 * The walk is driven by SQL over the triplets table, one hop at a time, so it
 * never loads a whole factory into memory and it follows links wherever they
 * land: into the same factory, into another factory (event → onBlock → block),
 * or onto a bare system concept (event → onBlockchain → counterparty). Each
 * reached node is materialised lazily — entities through their discovered
 * factory, concepts as {id, shortname}.
 */
class TraverseGraphTool implements McpToolInterface
{
    /** Hard cap on reached nodes, so a hub verb cannot blow up the response. */
    public const MAX_NODES = 500;

    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    private System $system;
    /** @var array<string, string>|null is_a shortname → registry name (built on first use) */
    private ?array $isaIndex = null;

    public function __construct(array &$factories, System $system)
    {
        $this->factories = &$factories;
        $this->system = $system;
    }

    public function name(): string
    {
        return 'sandra_traverse';
    }

    public function description(): string
    {
        return 'Traverse the graph from a starting entity following a verb link, hop by hop at SQL level. '
            . 'Follows links across factories (e.g. blockchainEvent -> onBlock -> counterpartyBloc) and onto '
            . 'bare concepts; each reached node is returned with its factory (or as a concept). '
            . 'Supports BFS, DFS, and ancestor (backward) traversal. Does NOT load the entire factory into memory.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The registered factory name of the starting entity',
                ],
                'startId' => [
                    'type' => 'integer',
                    'description' => 'Concept ID of the starting entity',
                ],
                'verb' => [
                    'type' => 'string',
                    'description' => 'The verb (relationship type) to follow',
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Maximum traversal depth (default 10)',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['forward', 'backward'],
                    'description' => 'forward = follow subject -> target (default); backward = follow target <- subject (ancestors)',
                ],
                'algorithm' => [
                    'type' => 'string',
                    'enum' => ['bfs', 'dfs'],
                    'description' => 'Traversal algorithm (default bfs)',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional: list of ref field names to include. If omitted, all fields are returned.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of reached nodes to return (default 100, max ' . self::MAX_NODES . ')',
                ],
            ],
            'required' => ['factory', 'startId', 'verb'],
        ];
    }

    public function execute(array $args): mixed
    {
        $name = $args['factory'] ?? '';
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Unknown factory: $name");
        }

        $startId = (int)($args['startId'] ?? 0);
        $verb = (string)($args['verb'] ?? '');
        $maxDepth = max(1, (int)($args['depth'] ?? 10));
        $backward = ($args['direction'] ?? 'forward') === 'backward';
        $dfs = ($args['algorithm'] ?? 'bfs') === 'dfs';
        $limit = min(max(1, (int)($args['limit'] ?? 100)), self::MAX_NODES);
        $fields = $args['fields'] ?? null;

        // The start must exist in the factory the caller named.
        if ($this->loadEntity($name, $startId) === null) {
            throw new \InvalidArgumentException("Entity with id $startId not found in factory '$name'");
        }

        $verbId = $this->system->systemConcept->get($verb, null, false);
        if (!is_numeric($verbId)) {
            return ['entities' => [], 'hasCycle' => false, 'totalFound' => 0, 'truncated' => false,
                'note' => "Verb '$verb' is not a known concept in this datagraph."];
        }
        $verbId = (int)$verbId;

        // Walk: (conceptId, depth). BFS pops from the front, DFS from the back.
        $visited = [$startId => true];
        $frontier = [[$startId, 0]];
        $reached = [];   // ordered [conceptId => depth]
        $hasCycle = false;
        $truncated = false;

        while ($frontier !== []) {
            [$current, $depth] = $dfs ? array_pop($frontier) : array_shift($frontier);
            if ($depth >= $maxDepth) {
                continue;
            }
            $next = $this->neighbors($current, $verbId, $backward);
            // DFS: push in reverse so the first neighbor is expanded first
            if ($dfs) {
                $next = array_reverse($next);
            }
            foreach ($next as $nid) {
                if (isset($visited[$nid])) {
                    $hasCycle = true;
                    continue;
                }
                if (count($reached) >= $limit) {
                    $truncated = true;
                    break 2;
                }
                $visited[$nid] = true;
                $reached[$nid] = $depth + 1;
                $frontier[] = [$nid, $depth + 1];
            }
        }

        $nodes = [];
        foreach ($reached as $cid => $depth) {
            $nodes[] = $this->describeNode($cid, $depth, $fields);
        }

        $result = [
            'entities' => $nodes,
            'hasCycle' => $hasCycle,
            'totalFound' => count($nodes),
            'truncated' => $truncated,
        ];
        if ($truncated) {
            $result['note'] = "Traversal stopped after $limit nodes. Lower depth or raise limit (max " . self::MAX_NODES . ").";
        }
        return $result;
    }

    /**
     * One hop over the triplets table.
     *
     * @return int[] neighbor concept ids, in link id order (stable)
     */
    private function neighbors(int $conceptId, int $verbId, bool $backward): array
    {
        $from = $backward ? 'idConceptTarget' : 'idConceptStart';
        $to = $backward ? 'idConceptStart' : 'idConceptTarget';
        $linkTable = $this->system->linkTable;
        $sql = "SELECT `$to` AS n FROM `$linkTable`
                WHERE `$from` = :cid AND idConceptLink = :verb AND flag != :deleted
                ORDER BY id ASC";
        $rows = QueryExecutor::fetchAll($this->system->getConnection(), $sql, [
            ':cid' => [$conceptId, PDO::PARAM_INT],
            ':verb' => [$verbId, PDO::PARAM_INT],
            ':deleted' => [(int)$this->system->deletedUNID, PDO::PARAM_INT],
        ]) ?? [];
        return array_values(array_unique(array_map(static fn($r) => (int)$r['n'], $rows)));
    }

    /**
     * Materialise a reached node: entity (via its factory) or bare concept.
     */
    private function describeNode(int $conceptId, int $depth, ?array $fields): array
    {
        $factoryName = $this->factoryOf($conceptId);
        if ($factoryName !== null) {
            $entity = $this->loadEntity($factoryName, $conceptId);
            if ($entity !== null) {
                $node = EntitySerializer::serialize($entity, $fields !== null ? ['fields' => $fields] : []);
                $node['factory'] = $factoryName;
                $node['depth'] = $depth;
                return $node;
            }
        }
        $shortname = $this->system->systemConcept->getShortname($conceptId);
        return [
            'id' => $conceptId,
            'concept' => $shortname,
            'depth' => $depth,
        ];
    }

    /**
     * Registry name of the factory a concept belongs to (via its is_a link), or null.
     */
    private function factoryOf(int $conceptId): ?string
    {
        $isaId = $this->system->systemConcept->get('is_a', null, false);
        if (!is_numeric($isaId)) {
            return null;
        }
        $targets = $this->neighbors($conceptId, (int)$isaId, false);
        if ($targets === []) {
            return null;
        }
        $index = $this->isaIndex();
        foreach ($targets as $isaTarget) {
            $isa = $this->system->systemConcept->getShortname($isaTarget);
            if ($isa !== null && isset($index[$isa])) {
                return $index[$isa];
            }
        }
        return null;
    }

    /** @return array<string, string> is_a shortname → registry name */
    private function isaIndex(): array
    {
        if ($this->isaIndex === null) {
            $this->isaIndex = [];
            foreach ($this->factories as $regName => $entry) {
                $isa = (string)$entry['factory']->entityIsa;
                // first registration wins (discovery dedups as isa_file for later ones)
                if (!isset($this->isaIndex[$isa])) {
                    $this->isaIndex[$isa] = $regName;
                }
            }
        }
        return $this->isaIndex;
    }

    /**
     * Load a single entity by concept id through its factory (SQL path, no full scan).
     */
    private function loadEntity(string $factoryName, int $conceptId): ?Entity
    {
        $factory = $this->factories[$factoryName]['factory'];
        $single = new EntityFactory($factory->entityIsa, $factory->entityContainedIn, $this->system);
        $single->conceptArray = [$conceptId];
        $single->populateLocal();
        foreach ($single->getEntities() ?: [] as $e) {
            if ((int)$e->subjectConcept->idConcept === $conceptId) {
                return $e;
            }
        }
        return null;
    }
}
