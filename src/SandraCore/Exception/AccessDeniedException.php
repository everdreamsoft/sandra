<?php
declare(strict_types=1);

namespace SandraCore\Exception;

/**
 * A principal attempted a write the graph ACL does not grant.
 *
 * Deliberately raised on WRITES only. Reads stay silent — an unreadable file
 * yields an empty result, never an error, so that a denial cannot be used to
 * confirm what it hides. A write has to fail loudly: the caller must not
 * believe it persisted something it did not.
 */
class AccessDeniedException extends SandraException
{
}
