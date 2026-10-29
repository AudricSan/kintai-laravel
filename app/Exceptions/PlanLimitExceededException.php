<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PlanLimitExceededException extends HttpException
{
    public function __construct(string $message = 'Plan limit exceeded')
    {
        parent::__construct(403, $message);
    }
}
