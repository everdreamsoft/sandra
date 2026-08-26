<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Acl\FileManager;
use SandraCore\Exception\AccessDeniedException;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * `sandra_create_file` — declare a file, i.e. a permission boundary.
 *
 * The only way to bring a file into being. Naming an unknown
 * `contained_in_file` on `sandra_create_entity` no longer mints one: a file
 * decides who can see what, and that is not something to create by accident.
 *
 * Creating one takes the write wildcard. Handing out a new boundary is an
 * administrative act — and the caller cannot grant themselves anything they did
 * not already have, since `sandra_allow_access` stays a protected verb
 * everywhere else.
 */
class CreateFileTool implements McpToolInterface, AclAwareToolInterface
{
    private ?AccessContext $access = null;

    public function __construct(private readonly System $system)
    {
    }

    public function setAccess(?AccessContext $access): void
    {
        $this->access = $access;
    }

    public function name(): string
    {
        return 'sandra_create_file';
    }

    public function description(): string
    {
        return 'Declare a file — the unit of the graph ACL. Entities filed under it are readable by '
            . 'whoever holds a grant on it, and by nobody else. By default the file is itself filed '
            . 'under the architect file, so its name does not show up in concept listings; pass '
            . 'architect=false for a deliberately public boundary. Requires the write wildcard.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'File name, e.g. "research_notes_file".'],
                'architect' => [
                    'type' => 'boolean',
                    'description' => 'Keep the file out of concept listings by filing it under the architect file. Default true.',
                ],
                'grantTo' => [
                    'type' => 'integer',
                    'description' => 'Optional principal concept id to grant read+write on the new file.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args): mixed
    {
        if ($this->access !== null && !$this->access->isAdmin()) {
            throw new AccessDeniedException(
                'Creating a file defines a new permission boundary and requires the write wildcard.'
            );
        }

        $name = (string) ($args['name'] ?? '');
        $architect = (bool) ($args['architect'] ?? true);
        $grantTo = isset($args['grantTo']) ? (int) $args['grantTo'] : null;

        $fileId = (new FileManager($this->system))->create($name, $architect, $grantTo);

        return [
            'file' => $name,
            'conceptId' => $fileId,
            'hiddenFromCatalogue' => $architect,
            'grantedTo' => $grantTo,
        ];
    }
}
