<?php

declare(strict_types=1);

namespace App\Validation;

interface ValidatorInterface
{
    public function validate(array $data): ValidationResult;
}
