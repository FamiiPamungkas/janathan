<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Exceptions;

use RuntimeException;

/**
 * A RouterOS command was rejected with a `!trap` reply. The message is the
 * router's own error text (e.g. a duplicate name), not a connection problem.
 */
class RouterosCommandException extends RuntimeException
{
}
