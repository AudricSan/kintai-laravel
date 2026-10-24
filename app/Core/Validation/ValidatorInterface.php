<?php

declare(strict_types=1);

namespace kintai\Core\Validation;

interface ValidatorInterface
{
    public function validate(array $data): ValidationResult;
}
