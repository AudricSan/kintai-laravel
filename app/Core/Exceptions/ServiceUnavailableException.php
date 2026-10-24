<?php

declare(strict_types=1);

namespace kintai\Core\Exceptions;

final class ServiceUnavailableException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable')
    {
        parent::__construct(503, $message);
    }
}
