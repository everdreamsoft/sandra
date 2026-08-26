<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

/**
 * Tools that only an administrator may call, and that nobody else should even
 * be told about.
 *
 * A marker, with no methods. Implementing it does two things: McpServer refuses
 * the tool to any principal without the write wildcard, and leaves it out of
 * `tools/list` for them entirely. The tool still enforces its own rule too — it
 * must, since a root request never reaches the server-side check — and a
 * capability that edits production should not rest on one check inside one
 * class.
 *
 * That omission is the point. A tool named in a listing describes a capability
 * the server has and where it points: `xcp_collection_admin` announces that
 * this server can edit a live marketplace. An agent that will only ever be
 * refused gains nothing from knowing, and an attacker gains a map.
 */
interface AdminOnlyToolInterface
{
}
