<?php
declare(strict_types=1);

namespace SandraCore\Mcp\Tools;

use SandraCore\Acl\AccessContext;
use SandraCore\Mcp\AclAwareToolInterface;
use SandraCore\Mcp\GroupManager;
use SandraCore\Mcp\McpToolInterface;
use SandraCore\System;

/**
 * `sandra_group` — create a shared group, and manage who is in it.
 *
 * One tool with an `action` rather than three, because the three share the same
 * precondition: they act AS a principal. Every check lives in GroupManager,
 * which also holds the single deliberate ACL bypass of the system; this class
 * only resolves the caller and forwards.
 */
class GroupTool implements McpToolInterface, AclAwareToolInterface
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
        return 'sandra_group';
    }

    public function description(): string
    {
        return 'Create a group and manage its membership. A group is a shared file: everything filed '
            . 'under it is readable and writable by every member, and joining is one triplet. '
            . 'The creator owns the group and is its first member; only the owner may add or remove '
            . 'members. Requires a principal-scoped token — a root token has no identity to own with.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'add_member', 'remove_member'],
                    'description' => 'create (requires label) · add_member / remove_member (require group and member)',
                ],
                'label' => ['type' => 'string', 'description' => 'Human-readable group name (create).'],
                'group' => ['type' => 'integer', 'description' => 'Group concept id.'],
                'member' => ['type' => 'integer', 'description' => 'Concept id of the user to add or remove.'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): mixed
    {
        $caller = $this->access?->principalId ?? 0;
        if ($caller <= 0) {
            throw new \InvalidArgumentException(
                'sandra_group acts as a principal: use a token bound to a user, not a root token.'
            );
        }

        $groups = new GroupManager($this->system);
        $action = (string) ($args['action'] ?? '');

        if ($action === 'create') {
            $groupId = $groups->create($caller, (string) ($args['label'] ?? ''));

            return [
                'group' => $groupId,
                'label' => $args['label'],
                'file' => GroupManager::groupFile($groupId),
                'owner' => $caller,
            ];
        }

        $groupId = (int) ($args['group'] ?? 0);
        $member = (int) ($args['member'] ?? 0);
        if ($groupId <= 0 || $member <= 0) {
            throw new \InvalidArgumentException('group and member are required and must be concept ids.');
        }

        match ($action) {
            'add_member' => $groups->addMember($caller, $groupId, $member),
            'remove_member' => $groups->removeMember($caller, $groupId, $member),
            default => throw new \InvalidArgumentException("Unknown action '$action'."),
        };

        return [
            'group' => $groupId,
            'member' => $member,
            'action' => $action,
            'members' => $groups->isMember($member, $groupId) ? 'in' : 'out',
        ];
    }
}
