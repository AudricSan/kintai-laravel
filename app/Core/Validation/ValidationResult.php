<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

use kintai\Core\Exceptions\ValidationException;

final class ValidationResult
{
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): string
    {
        return $this->errors[0] ?? '';
    }

    public function throwIfInvalid(): void
    {
        if (!$this->valid) {
            throw new ValidationException($this->errors);
        }
    }
}
