<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\Acl\AccessContext;

/**
 * Tools that enforce the graph-native ACL when a request is scoped to a
 * principal (token with principal_concept_id). McpServer sets the access
 * before execute() and always resets it afterwards.
 *
 * Tools NOT implementing this interface are blocked entirely for
 * principal-scoped requests (default-deny — no leaky tools).
 */
interface AclAwareToolInterface
{
    public function setAccess(?AccessContext $access): void;
}
