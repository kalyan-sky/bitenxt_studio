<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Service;

/** A request that cannot be completed, with a message that is safe to show. */
final class ServiceError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $error, string $message = '')
    {
        parent::__construct($message);
    }
}
