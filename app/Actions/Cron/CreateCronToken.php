<?php

namespace App\Actions\Cron;

use App\Activity\ActivityLogger;
use App\Models\CronToken;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crée un jeton d'appel HTTP des tâches planifiées (Owner seulement). Le jeton en clair n'est rendu qu'ici : seule son
 * empreinte est stockée.
 */
class CreateCronToken
{
    public const NAME_MAX = 100;

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @return string Le jeton en clair, à montrer une seule fois.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $name): string
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $name = Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:'.self::NAME_MAX]], [], ['name' => __('Name')])->validate()['name'];

        $plain = bin2hex(random_bytes(32));
        $token = CronToken::query()->create(['name' => $name, 'token_hash' => CronToken::hash($plain), 'created_by' => $actor->id]);

        // Le jeton lui-même n'est jamais journalisé.
        $this->logger->record('cron.token_created', $token, null, ['name' => $name], $actor);

        return $plain;
    }
}
