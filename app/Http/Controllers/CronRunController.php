<?php

namespace App\Http\Controllers;

use App\Models\CronToken;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Lance les tâches planifiées dues (l'équivalent de `php artisan schedule:run`) pour les hébergements sans terminal ni cron :
 * un service « cron en ligne » ou la tâche planifiée de l'hébergeur appelle `/cron/run` chaque minute.
 *
 * Route publique, hors du groupe `web` (ni session, ni cookie, ni CSRF) : le jeton fait foi. Il se passe dans l'en-tête
 * `Authorization: Bearer` (préféré : une URL finit dans les journaux d'accès du serveur) ou dans `?token=` pour les services qui
 * ne savent appeler qu'une URL. Seule l'empreinte du jeton est en base. Jeton absent, invalide ou révoqué : la même réponse 404.
 * Un verrou empêche deux exécutions simultanées (409).
 */
class CronRunController extends Controller
{
    /** Durée maximale du verrou (secondes) : au-delà, une exécution plantée n'empêche plus la suivante. */
    private const LOCK_SECONDS = 900;

    public function __invoke(Request $request): JsonResponse
    {
        $token = CronToken::query()->where('token_hash', CronToken::hash($this->plainToken($request)))->first();

        if ($token === null) {
            return $this->respond(['error' => 'Not Found'], 404);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $lock = null;

        try {
            $lock = Cache::lock('kintai.cron.run', self::LOCK_SECONDS);

            if (! $lock->get()) {
                return $this->respond(['status' => 'busy'], 409);
            }
        } catch (Throwable) {
            $lock = null; // Cache sans verrou : on exécute quand même, `schedule:run` ne relance pas deux fois une même minute.
        }

        try {
            // Une tâche peut durer (sauvegarde) : ni la limite de temps de PHP ni la fermeture du client ne doivent l'interrompre.
            @set_time_limit(0);
            ignore_user_abort(true);

            $ran = 0;
            Event::listen(ScheduledTaskFinished::class, function () use (&$ran): void {
                $ran++;
            });

            $exitCode = Artisan::call('schedule:run');

            return $this->respond(['status' => $exitCode === 0 ? 'ok' : 'error', 'tasks' => $ran], $exitCode === 0 ? 200 : 500);
        } catch (Throwable $e) {
            report($e);

            return $this->respond(['status' => 'error'], 500);
        } finally {
            $lock?->release();
        }
    }

    private function plainToken(Request $request): string
    {
        $token = $request->bearerToken() ?? $request->query('token');

        return is_string($token) && strlen($token) <= 255 ? $token : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
