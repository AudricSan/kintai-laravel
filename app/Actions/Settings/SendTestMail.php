<?php

namespace App\Actions\Settings;

use App\Activity\ActivityLogger;
use App\Mail\MailTestMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envoie un mail de test (Owner seulement) à une adresse saisie, avec l'expéditeur et le transport réels, et rend le
 * résultat : un échec d'envoi n'est pas une exception mais une erreur lisible à afficher.
 */
class SendTestMail
{
    /** Longueur maximale du message d'erreur affiché. */
    private const ERROR_MAX = 300;

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @return array{success: bool, error: string|null}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $to): array
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $to = Validator::make(['to' => trim($to)], ['to' => ['required', 'string', 'email:filter', 'max:255']], [], ['to' => __('email address')])->validate()['to'];

        try {
            Mail::to($to)->send(new MailTestMessage(now((string) config('kintai.timezone'))->format('Y-m-d H:i:s'), (string) config('mail.default')));
        } catch (Throwable $e) {
            report($e);

            $this->logger->record('settings.mail_tested', null, null, ['success' => false], $actor);

            return ['success' => false, 'error' => mb_substr(trim($e->getMessage()) ?: $e::class, 0, self::ERROR_MAX)];
        }

        $this->logger->record('settings.mail_tested', null, null, ['success' => true], $actor);

        return ['success' => true, 'error' => null];
    }
}
