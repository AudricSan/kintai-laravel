<?php

declare(strict_types=1);

namespace App\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    public function __construct(public readonly array $allowedMethods)
    {
        parent::__construct(405, 'Method Not Allowed', ['Allow' => implode(', ', $allowedMethods)]);
    }
}
