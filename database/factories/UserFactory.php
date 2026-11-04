<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => null,
            'color' => '#3B82F6',
            'is_active' => true,
        ];
    }

    /**
     * Compte désactivé.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Compte anonymisé : identité effacée, désactivé et placé dans la corbeille.
     */
    public function anonymized(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Utilisateur supprimé',
            'first_name' => '[Supprimé]',
            'last_name' => '',
            'furigana_first_name' => null,
            'furigana_last_name' => null,
            'email' => 'anonymized-'.fake()->unique()->uuid().'@invalid.example',
            'employee_code' => null,
            'avatar_path' => null,
            'is_active' => false,
            'deleted_at' => now(),
        ]);
    }

    /**
     * Compte dont le mot de passe doit être changé à la prochaine connexion.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => ['must_change_password' => true]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
