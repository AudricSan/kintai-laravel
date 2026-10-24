<?php

declare(strict_types=1);

namespace kintai\Core\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct(403, $message);
    }
}
