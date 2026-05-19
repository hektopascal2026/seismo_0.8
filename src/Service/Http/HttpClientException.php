<?php

declare(strict_types=1);

namespace Seismo\Service\Http;

use RuntimeException;

/**
 * Thrown by BaseClient when the transport itself fails — DNS error, connect
 * timeout, TLS failure, unreadable response. A non-2xx HTTP status is NOT an
 * exception; the caller inspects Response::$status and decides.
 */
final class HttpClientException extends RuntimeException
{
}
