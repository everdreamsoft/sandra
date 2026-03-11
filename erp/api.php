<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SandraCore\System;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\QueryExecutor;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$system = new System('phpUnit_', false, '127.0.0.1', 'laravel', 'root', 'password');

$action = $_GET['action'] ?? '';
$factory = $_GET['factory'] ?? '';

try {
    switch ($action) {
        case 'factories':
            echo json_encode(handleFactories($system));
            break;
        case 'list':
            echo json_encode(handleList($system, $factory, $_GET));
            break;
        case 'get':
            echo json_encode(handleGet($system, $factory, (int)($_GET['id'] ?? 0)));
            break;
        case 'create':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(handleCreate($system, $factory, $input));
            break;
        case 'update':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(handleUpdate($system, $factory, (int)($_GET['id'] ?? 0), $input));
            break;
        case 'search':
            echo json_encode(handleSearch($system, $factory, $_GET['q'] ?? ''));
            break;
        case 'triplets':
            echo json_encode(handleTriplets($system, (int)($_GET['id'] ?? 0)));
            break;
        case 'dashboard':
            echo json_encode(handleDashboard($system));
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleFactories(System $system): array
{
    $discovery = new \SandraCore\Mcp\FactoryDiscovery($system);
    $result = [];
    foreach ($discovery->discover() as $name => $factory) {
        $countFactory = new EntityFactory($factory->entityIsa, $factory->entityContainedIn, $system);
        $countFactory->populateLocal();
        $result[] = [
            'name' => $name,
            'isa' => $factory->entityIsa,
            'count' => count($countFactory->getEntities()),
        ];
    }
    return $result;
}

function handleList(System $system, string $factoryName, array $params): array
{
    $erpFactories = getErpFactories();
    if (!isset($erpFactories[$factoryName])) {
        throw new \InvalidArgumentException("Unknown factory: $factoryName");
    }

    $meta = $erpFactories[$factoryName];
    $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
    $factory->populateLocal();
    $entities = $factory->getEntities();

    $items = [];
    foreach ($entities as $entity) {
        $items[] = EntitySerializer::serialize($entity);
    }

    return ['items' => $items, 'total' => count($items)];
}

function handleGet(System $system, string $factoryName, int $id): array
{
    $erpFactories = getErpFactories();
    if (!isset($erpFactories[$factoryName])) {
        throw new \InvalidArgumentException("Unknown factory: $factoryName");
    }

    $meta = $erpFactories[$factoryName];
    $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
    $factory->populateLocal();

    foreach ($factory->getEntities() as $entity) {
        if ((int)$entity->subjectConcept->idConcept === $id) {
            $result = EntitySerializer::serialize($entity);

            // Get triplets for relationships
            $pdo = $system->getConnection();
            $linkTable = $system->linkTable;
            $conceptTable = $system->conceptTable;
            $deletedId = (int)$system->deletedUNID;

            $sql = "SELECT l.id AS linkId, l.idConceptStart, l.idConceptLink, l.idConceptTarget,
                    cs.shortname AS subject_name, cl.shortname AS verb_name, ct.shortname AS target_name
                    FROM `{$linkTable}` l
                    LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                    LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                    LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                    WHERE l.idConceptStart = :id AND l.flag != :deleted
                    LIMIT 100";
            $triplets = QueryExecutor::fetchAll($pdo, $sql, [
                ':id' => [$id, \PDO::PARAM_INT],
                ':deleted' => [$deletedId, \PDO::PARAM_INT],
            ]);
            // Resolve target entity names for relationships
            $refTable = $system->tableReference;
            $result['relationships'] = array_values(array_filter(array_map(function($r) use ($pdo, $linkTable, $conceptTable, $refTable, $deletedId) {
                $verb = $r['verb_name'];
                if (in_array($verb, ['is_a', 'contained_in_file'])) return null;
                $targetId = (int)$r['idConceptTarget'];
                // Try to resolve target name from references
                $nameConcept = null;
                $nameRow = QueryExecutor::fetchAll($pdo,
                    "SELECT c.id FROM `{$conceptTable}` c WHERE c.shortname = 'name' LIMIT 1", []);
                if ($nameRow) {
                    $nameCid = (int)$nameRow[0]['id'];
                    // Find the triplet where target is the entity subject, look for its refs
                    $refRow = QueryExecutor::fetchAll($pdo,
                        "SELECT r.value FROM `{$refTable}` r
                         JOIN `{$linkTable}` l ON r.linkReferenced = l.id
                         WHERE l.idConceptStart = :tid AND r.idConcept = :nameCid AND l.flag != :del LIMIT 1",
                        [':tid' => [$targetId, \PDO::PARAM_INT], ':nameCid' => [$nameCid, \PDO::PARAM_INT], ':del' => [$deletedId, \PDO::PARAM_INT]]
                    );
                    if ($refRow) $nameConcept = $refRow[0]['value'];
                }
                // Fallback: try order_number or invoice_number
                if (!$nameConcept) {
                    foreach (['order_number', 'invoice_number'] as $field) {
                        $fc = QueryExecutor::fetchAll($pdo, "SELECT id FROM `{$conceptTable}` WHERE shortname = :f LIMIT 1", [':f' => $field]);
                        if ($fc) {
                            $refRow = QueryExecutor::fetchAll($pdo,
                                "SELECT r.value FROM `{$refTable}` r JOIN `{$linkTable}` l ON r.linkReferenced = l.id WHERE l.idConceptStart = :tid AND r.idConcept = :cid AND l.flag != :del LIMIT 1",
                                [':tid' => [$targetId, \PDO::PARAM_INT], ':cid' => [(int)$fc[0]['id'], \PDO::PARAM_INT], ':del' => [$deletedId, \PDO::PARAM_INT]]
                            );
                            if ($refRow) { $nameConcept = $refRow[0]['value']; break; }
                        }
                    }
                }
                return [
                    'id' => (int)$r['linkId'],
                    'verb_name' => $verb,
                    'target_name' => $nameConcept ?? $r['target_name'] ?? ('#' . $targetId),
                    'idConceptTarget' => $targetId,
                ];
            }, $triplets ?: [])));

            return $result;
        }
    }

    throw new \RuntimeException("Entity $id not found in $factoryName");
}

function handleCreate(System $system, string $factoryName, array $input): array
{
    $erpFactories = getErpFactories();
    if (!isset($erpFactories[$factoryName])) {
        throw new \InvalidArgumentException("Unknown factory: $factoryName");
    }

    $meta = $erpFactories[$factoryName];
    $refs = $input['refs'] ?? [];
    $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
    $entity = $factory->createNew($refs);

    return EntitySerializer::serialize($entity);
}

function handleUpdate(System $system, string $factoryName, int $id, array $input): array
{
    $erpFactories = getErpFactories();
    if (!isset($erpFactories[$factoryName])) {
        throw new \InvalidArgumentException("Unknown factory: $factoryName");
    }

    $meta = $erpFactories[$factoryName];
    $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
    $factory->populateLocal();

    foreach ($factory->getEntities() as $entity) {
        if ((int)$entity->subjectConcept->idConcept === $id) {
            $refs = $input['refs'] ?? [];
            foreach ($refs as $key => $value) {
                $entity->createOrUpdateRef($key, $value);
            }
            return EntitySerializer::serialize($entity);
        }
    }

    throw new \RuntimeException("Entity $id not found");
}

function handleSearch(System $system, string $factoryName, string $query): array
{
    $erpFactories = getErpFactories();
    $targetFactories = $factoryName ? [$factoryName => $erpFactories[$factoryName]] : $erpFactories;

    $results = [];
    foreach ($targetFactories as $name => $meta) {
        $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
        $factory->populateFromSearchResults($query, 0, 20);
        $entities = $factory->getEntities();
        foreach ($entities as $entity) {
            $item = EntitySerializer::serialize($entity);
            $item['_factory'] = $name;
            $results[] = $item;
        }
    }

    return ['items' => $results, 'total' => count($results)];
}

function handleTriplets(System $system, int $conceptId): array
{
    $pdo = $system->getConnection();
    $linkTable = $system->linkTable;
    $conceptTable = $system->conceptTable;
    $deletedId = (int)$system->deletedUNID;

    $results = [];
    foreach (['outgoing' => 'idConceptStart', 'incoming' => 'idConceptTarget'] as $dir => $col) {
        $sql = "SELECT l.id AS linkId, l.idConceptStart, l.idConceptLink, l.idConceptTarget,
                cs.shortname AS subject_name, cl.shortname AS verb_name, ct.shortname AS target_name
                FROM `{$linkTable}` l
                LEFT JOIN `{$conceptTable}` cs ON l.idConceptStart = cs.id
                LEFT JOIN `{$conceptTable}` cl ON l.idConceptLink = cl.id
                LEFT JOIN `{$conceptTable}` ct ON l.idConceptTarget = ct.id
                WHERE l.{$col} = :id AND l.flag != :deleted
                LIMIT 100";
        $rows = QueryExecutor::fetchAll($pdo, $sql, [
            ':id' => [$conceptId, \PDO::PARAM_INT],
            ':deleted' => [$deletedId, \PDO::PARAM_INT],
        ]);
        foreach ($rows ?: [] as $row) {
            $results[] = [
                'direction' => $dir,
                'verb_name' => $row['verb_name'],
                'subject_name' => $row['subject_name'],
                'target_name' => $row['target_name'],
                'subjectId' => (int)$row['idConceptStart'],
                'targetId' => (int)$row['idConceptTarget'],
            ];
        }
    }
    return $results;
}

function handleDashboard(System $system): array
{
    $erpFactories = getErpFactories();
    $stats = [];
    foreach ($erpFactories as $name => $meta) {
        $factory = new EntityFactory($meta['isa'], $meta['cif'], $system);
        $factory->populateLocal();
        $entities = $factory->getEntities();
        $stats[$name] = ['count' => count($entities)];

        if ($name === 'order' || $name === 'invoice') {
            $totalAmount = 0;
            $statusCounts = [];
            foreach ($entities as $entity) {
                $refs = EntitySerializer::extractRefs($entity);
                $totalAmount += (float)($refs['total'] ?? 0);
                $status = $refs['status'] ?? 'unknown';
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }
            $stats[$name]['totalAmount'] = $totalAmount;
            $stats[$name]['byStatus'] = $statusCounts;
        }

        if ($name === 'product') {
            $totalStock = 0;
            $totalValue = 0;
            foreach ($entities as $entity) {
                $refs = EntitySerializer::extractRefs($entity);
                $stock = (int)($refs['stock'] ?? 0);
                $price = (float)($refs['price'] ?? 0);
                $totalStock += $stock;
                $totalValue += $stock * $price;
            }
            $stats[$name]['totalStock'] = $totalStock;
            $stats[$name]['inventoryValue'] = $totalValue;
        }
    }
    return $stats;
}

function getErpFactories(): array
{
    return [
        'client' => ['isa' => 'client', 'cif' => 'client_file'],
        'product' => ['isa' => 'product', 'cif' => 'product_file'],
        'order' => ['isa' => 'order', 'cif' => 'order_file'],
        'invoice' => ['isa' => 'invoice', 'cif' => 'invoice_file'],
    ];
}
