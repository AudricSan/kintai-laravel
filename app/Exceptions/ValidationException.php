<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    public function __construct(
        public readonly array $errors,
        ?string $message = null,
    ) {
        parent::__construct(422, $message ?? __('error_422_message'));
    }

    /** Exécute un validateur de Laravel ; au premier refus, lève cette exception (un message par champ). */
    public static function check(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        try {
            $validator->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // L'erreur d'un élément de liste (tags.2) est portée par le champ (tags).
            $errors = [];
            foreach ($e->errors() as $name => $list) {
                $errors[(string) preg_replace('/\.(?:\d+|\*)$/', '', (string) $name)] ??= (string) reset($list);
            }
            throw new self($errors, reset($errors) ?: null);
        }
    }
}
