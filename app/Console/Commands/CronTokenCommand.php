<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CronToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * `php artisan kintai:cron-token --user-id=1 --job=backup` : crée un jeton pour l'URL /cron/run/{tâche}.
 *
 * Le jeton n'est affiché qu'une fois : seule son empreinte SHA-256 est conservée, comme pour les jetons d'API.
 */
final class CronTokenCommand extends Command
{
    protected $signature = 'kintai:cron-token
        {--user-id= : Identifiant de l\'utilisateur propriétaire du jeton}
        {--job= : Tâche autorisée (vide : n\'importe quelle tâche)}
        {--name= : Libellé du jeton}
        {--expires-in= : Durée de validité en secondes (vide : n\'expire pas)}';

    protected $description = 'Crée un jeton pour /cron/run/{tâche}';

    public function handle(): int
    {
        $userId = (int) $this->option('user-id');
        if ($userId <= 0) {
            $this->error('--user-id est obligatoire.');

            return self::FAILURE;
        }
        if (User::find($userId) === null) {
            $this->error("Aucun utilisateur n'a l'identifiant {$userId}.");

            return self::FAILURE;
        }

        $job       = (string) $this->option('job');
        $expiresIn = $this->option('expires-in');
        $rawToken  = bin2hex(random_bytes(32));

        CronToken::create([
            'user_id'    => $userId,
            'job_name'   => $job,
            'token'      => hash_token($rawToken),
            'name'       => $this->option('name'),
            'expires_at' => $expiresIn !== null && $expiresIn !== '' ? date('Y-m-d H:i:s', time() + (int) $expiresIn) : null,
        ]);

        $this->line('Jeton créé' . ($job !== '' ? " pour la tâche '{$job}'" : ' (toute tâche)') . '.');
        $this->line('Jeton (à conserver maintenant, il ne sera plus affiché) :');
        $this->line($rawToken);
        $this->newLine();
        $this->line('Exemple :');
        $this->line('  curl "https://votre-site.example/cron/run/' . ($job !== '' ? $job : '{tâche}') . "?token={$rawToken}\"");

        return self::SUCCESS;
    }
}
