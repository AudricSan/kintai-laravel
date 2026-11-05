<?php

namespace App\Http\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ShiftWarningsException;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Format d'erreur unique de `/api/*` : `{"error": "<message traduit>", "code": "<code_stable>"}`, plus `errors`
 * (champ => messages) pour une validation. Toujours du JSON, quel que soit l'en-tête `Accept`.
 *
 * Codes : unauthenticated (401), forbidden (403), not_found (404), method_not_allowed (405), validation_failed (422),
 * shift_warnings (409, avec `warnings`), too_many_requests (429, avec `Retry-After`), server_error (500, sans détail hors du mode debug), bad_request (autre
 * erreur 4xx), plus les codes propres à un endpoint (`ApiException`).
 */
final class ApiErrorRenderer
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        // L'erreur peut survenir avant les middlewares (route inconnue) : la langue est fixée ici.
        SetApiLocale::apply($request);

        if ($e instanceof ValidationException) {
            return self::json(422, 'validation_failed', $e->getMessage(), ['errors' => $e->errors()]);
        }

        if ($e instanceof ShiftWarningsException) {
            return self::json(409, 'shift_warnings', __('This shift raises warnings. Send it again with the force option to save it anyway.'), [
                'warnings' => array_map(fn ($issue): array => [
                    'kind' => $issue->kind->value,
                    'message' => $issue->message,
                    'conflicting_shift_id' => $issue->conflictingShiftId,
                ], $e->issues),
            ]);
        }

        if ($e instanceof ApiException) {
            return self::json($e->getStatusCode(), $e->errorCode, $e->getMessage(), [], $e->getHeaders());
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, 'unauthenticated', __('Authentication is required.'));
        }

        if ($e instanceof AuthorizationException) {
            return self::json(403, 'forbidden', __('You are not allowed to do this.'));
        }

        if ($e instanceof ModelNotFoundException) {
            return self::json(404, 'not_found', __('Resource not found.'));
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::fromStatus($e->getStatusCode(), $e->getHeaders());
        }

        $extra = config('app.debug') ? ['exception' => $e::class, 'detail' => $e->getMessage()] : [];

        return self::json(500, 'server_error', __('An unexpected error occurred.'), $extra);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function fromStatus(int $status, array $headers): JsonResponse
    {
        return match (true) {
            $status === 401 => self::json(401, 'unauthenticated', __('Authentication is required.'), [], $headers),
            $status === 403 => self::json(403, 'forbidden', __('You are not allowed to do this.'), [], $headers),
            $status === 404 => self::json(404, 'not_found', __('Resource not found.'), [], $headers),
            $status === 405 => self::json(405, 'method_not_allowed', __('This method is not allowed on this URL.'), [], $headers),
            $status === 429 => self::json(429, 'too_many_requests', __('Too many requests. Try again later.'), [], $headers),
            $status >= 500 => self::json($status, 'server_error', __('An unexpected error occurred.'), [], $headers),
            default => self::json($status, 'bad_request', __('The request is invalid.'), [], $headers),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private static function json(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code] + $extra, $status, $headers);
    }
}
