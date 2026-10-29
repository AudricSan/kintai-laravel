<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use App\Exceptions\HttpException;
use App\Exceptions\MethodNotAllowedException;
use App\Exceptions\ValidationException;
use App\Services\Log;
use App\ValidationFeedback;
use App\View\ViewRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Rend les exceptions de l'application (withExceptions() de bootstrap/app.php) : pages d'erreur Kintai (errors.*) pour
 * le navigateur, JSON {error, errors?} pour l'API et l'AJAX, retour au formulaire avec les messages précis pour une
 * validation. Journalise les erreurs inattendues dans storage/logs/error.log et dans le journal d'activité.
 */
final class ErrorResponder
{
    public static function render(Throwable $e, Request $request): ?Response
    {
        // Réponse déjà construite par le code appelant (abort personnalisé, redirection d'un middleware).
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $wantsJson = $request->wantsJsonResponse() || $request->attributes->get('wantsJson', false);

        // Validateur de Laravel (validator(...)->validate(), Validator::make) : même réponse qu'une validation Kintai.
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errors = array_map(static fn(array $messages): string => (string) reset($messages), $e->errors());
            $e      = new ValidationException($errors, $errors !== [] ? reset($errors) : null);
        }

        if ($e instanceof ValidationException) {
            if ($wantsJson) {
                return response()->json(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
            }
            // Formulaire web : retour au formulaire avec les messages précis et la saisie conservée.
            return ValidationFeedback::redirectBack($e, $request)
                ?? self::page(422, implode(' ', ValidationFeedback::messages($e)));
        }

        if ($e instanceof MethodNotAllowedException || $e instanceof MethodNotAllowedHttpException) {
            $allow = $e instanceof MethodNotAllowedException ? $e->allowedMethods : explode(', ', (string) ($e->getHeaders()['Allow'] ?? ''));
            $response = $wantsJson
                ? response()->json(['error' => 'Method Not Allowed'], 405)
                : self::page(405, 'Method Not Allowed');

            return $response->header('Allow', implode(', ', array_filter($allow)));
        }

        if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
            // Message du routeur de Kintai pour une URL inconnue (affiché sur la page 404).
            $message = $e instanceof NotFoundHttpException && ($e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route'))
                ? sprintf('No route matches [%s %s].', $request->method(), $request->slashPath())
                : ($e->getMessage() !== '' ? $e->getMessage() : 'Not Found');

            return $wantsJson ? response()->json(['error' => $message], 404) : self::page(404, $message);
        }

        if ($e instanceof AuthorizationException) {
            return $wantsJson ? response()->json(['error' => $e->getMessage()], 403) : self::page(403, $e->getMessage());
        }

        if ($e instanceof TokenMismatchException) {
            return $wantsJson ? response()->json(['error' => $e->getMessage()], 419) : self::page(419, $e->getMessage());
        }

        if ($e instanceof HttpException || $e instanceof HttpExceptionInterface) {
            $status  = $e instanceof HttpException ? $e->statusCode : $e->getStatusCode();
            $headers = $e instanceof HttpException ? $e->headers : $e->getHeaders();
            $response = $wantsJson
                ? response()->json(['error' => $e->getMessage()], $status)
                : self::page($status, $e->getMessage());
            // En-têtes portés par l'exception (ex. Retry-After d'un 429).
            foreach ($headers as $name => $value) {
                $response->headers->set((string) $name, (string) $value);
            }

            return $response;
        }

        // Erreur inattendue (journalisée par report()).
        $message = __('error_500_message');

        return $wantsJson ? response()->json(['error' => $message], 500) : self::page(500, $message);
    }

    /** Page d'erreur Kintai (errors.{status}), ou un HTML minimal si la vue manque. */
    public static function page(int $status, string $message): Response
    {
        try {
            $html = app(ViewRenderer::class)->render("errors.{$status}", [
                'status'  => $status,
                'message' => $message,
            ]);

            return response($html, $status);
        } catch (Throwable) {
            return response("<h1>{$status}</h1><p>" . htmlspecialchars($message) . '</p>', $status);
        }
    }

    /** Erreur inattendue : fichier storage/logs/error.log, puis journal d'activité (jamais d'exception en retour). */
    public static function log(Throwable $e): void
    {
        $logDir = BASE_PATH . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $entry = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );

        @file_put_contents($logDir . '/error.log', $entry, FILE_APPEND | LOCK_EX);

        // Également logger en base via le système unifié
        try {
            Log::error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
        } catch (Throwable) {
            // Le logging ne doit jamais faire planter l'application
        }
    }
}
