<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Les messages de validation de Laravel existent en français, avec des noms de champs lisibles.
 */
class FrenchValidationMessagesTest extends TestCase
{
    public function test_validation_messages_are_in_french_with_readable_field_names(): void
    {
        app()->setLocale('fr');

        $errors = Validator::make(['email' => 'nope'], ['first_name' => 'required', 'email' => 'email'])->errors();

        $this->assertSame('Le champ prénom est obligatoire.', $errors->first('first_name'));
        $this->assertSame('Le champ adresse e-mail doit être une adresse e-mail valide.', $errors->first('email'));
    }

    public function test_every_framework_validation_message_has_a_french_version(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $french = require lang_path('fr/validation.php');

        $missing = array_diff(
            array_keys(Arr::dot(Arr::except($english, ['custom', 'attributes']))),
            array_keys(Arr::dot(Arr::except($french, ['custom', 'attributes']))),
        );

        $this->assertSame([], array_values($missing));
    }
}
