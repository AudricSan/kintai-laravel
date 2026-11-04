<?php

namespace Database\Factories;

use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IcalToken>
 */
class IcalTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_id' => Store::factory(),
            'token_hash' => IcalToken::hash(Str::random(64)),
        ];
    }

    /**
     * Jeton connu : l'empreinte de `$token`.
     */
    public function forToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => IcalToken::hash($token)]);
    }
}
