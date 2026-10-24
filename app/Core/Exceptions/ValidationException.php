<?php

declare(strict_types=1);

namespace kintai\Core\Exceptions;

final class ValidationException extends HttpException
{
    public function __construct(
        public readonly array $errors,
        ?string $message = null,
    ) {
        parent::__construct(422, $message ?? __('error_422_message'));
    }
}
