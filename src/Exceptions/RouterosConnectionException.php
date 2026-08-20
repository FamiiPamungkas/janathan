<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Exceptions;

use RuntimeException;

/**
 * A RouterOS connection-level failure (e.g. timeout, refused, auth error).
 * The message is supplied by the caller and must stay credential-safe.
 */
class RouterosConnectionException extends RuntimeException
{
}
