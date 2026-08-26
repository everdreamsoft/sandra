<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\WriteGuard;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\FactoryDiscovery;
use SandraCore\EntityFactory;
use SandraCore\Mcp\EmbeddingService;
use SandraCore\Mcp\EntitySerializer;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

class CreateEntityTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    /**
     * Registry key for (is_a, requested file). Falls back to the bare is_a when
     * no file is named, or when the registered factory already sits in it.
     */
    private function keyFor(string $isa, ?string $cif): string
    {
        if ($cif === null || !isset($this->factories[$isa])) {
            return $isa;
        }
        if ((string) $this->factories[$isa]['factory']->entityContainedIn === $cif) {
            return $isa;
        }

        return FactoryDiscovery::qualifiedName($isa, $cif);
    }
    /** @var array<string, array{factory: EntityFactory, options: array}> */
    private array $factories;
    /** @var array<string, array{isa: string, cif: string, options: array}> */
    private array $factoryMeta;
    private System $system;
    private ?EmbeddingService $embeddingService;

    public function __construct(array &$factories, array &$factoryMeta, System $system, ?EmbeddingService $embeddingService = null)
    {
        $this->factories = &$factories;
        $this->factoryMeta = &$factoryMeta;
        $this->system = $system;
        $this->embeddingService = $embeddingService;
    }

    public function name(): string
    {
        return 'sandra_create_entity';
    }

    public function description(): string
    {
        return 'Create a new entity in a factory with the given reference values.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factory' => [
                    'type' => 'string',
                    'description' => 'The factory name (is_a). If it does not exist yet, it will be created automatically.',
                ],
                'conceptId' => [
                    'type' => 'integer',
                    'description' => 'File an EXISTING concept under this factory instead of creating a new one — a facet. '
                        . 'The concept keeps its identity and gains a second, separate set of refs, readable only by '
                        . 'those granted this file. Requires at least one ref.',
                ],
                'contained_in_file' => [
                    'type' => 'string',
                    'description' => 'Optional contained_in_file name. Defaults to "<factory>_file" if omitted.',
                ],
                'refs' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of reference data (e.g. {"name": "Fido", "breed": "Lab"})',
                ],
                'storage' => [
                    'type' => 'string',
                    'description' => 'Optional: long text content to store (e.g. article body, description, HTML). Stored separately from refs.',
                ],
            ],
            'required' => ['factory', 'refs'],
        ];
    }

    public function execute(array $args): mixed
    {
        $isa = (string) ($args['factory'] ?? '');
        $refs = $args['refs'] ?? [];

        $guard = WriteGuard::forAccess($this->system, $this->access);

        // A named file WINS over an already-registered is_a: with facets the
        // same is_a lives in several files, and silently writing into the
        // incumbent one is a cross-file write into someone else's facet. The
        // registry key may now be qualified — the is_a never is.
        $name = $this->keyFor($isa, $args['contained_in_file'] ?? null);

        if (!isset($this->factories[$name])) {
            $cif = $args['contained_in_file'] ?? $isa . '_file';
            // An unknown factory name silently creates one here, so this path
            // is a factory creation and takes the wildcard like the explicit
            // tool does.
            $guard?->assertCanCreateFactory($cif);
            $options = ['brothers' => [], 'joined' => []];
            $factory = new EntityFactory($isa, $cif, $this->system);
            $this->factories[$name] = [
                'factory' => $factory,
                'options' => $options,
            ];
            $this->factoryMeta[$name] = [
                'isa' => $isa,
                'cif' => $cif,
                'options' => $options,
            ];
        }

        $factory = $this->factories[$name]['factory'];
        $file = (string) $factory->entityContainedIn;
        $conceptId = (int) ($args['conceptId'] ?? 0);

        if ($conceptId > 0) {
            // Facet: file an EXISTING concept here rather than mint a new one.
            // Its own rule — write the destination, and be able to read the
            // concept — so that a public thing can be annotated privately
            // without letting a caller reveal one it cannot see.
            $guard?->assertCanAttachFacet($conceptId, (int) $this->system->systemConcept->get($file));
            $entity = $factory->attachFacet($conceptId, $refs);
        } else {
            $guard?->assertCanCreateInFile($file);
            $entity = $factory->createNew($refs);
        }

        $storage = $args['storage'] ?? null;
        if ($storage !== null) {
            $entity->setStorage($storage);
        }

        if ($this->embeddingService !== null && $this->embeddingService->isAvailable()) {
            try {
                $this->embeddingService->embedEntity($entity);
            } catch (\Throwable $e) {
                // Non-fatal: embedding failure should not block entity creation
            }
        }

        $serializeOptions = $storage !== null ? ['include_storage' => true] : [];
        return EntitySerializer::serialize($entity, $serializeOptions);
    }
}
