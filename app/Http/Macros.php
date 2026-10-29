<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Response;
use kintai\Http\Routing\Routes;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Macros Laravel (Request::macro, ResponseFactory::macro) qui remplacent les helpers de l'ancien Core\Request et
 * Core\Response : exports, réponses d'API standardisées, lecture des cases à cocher.
 */
final class Macros
{
    public static function register(): void
    {
        self::requestMacros();
        self::routeMacros();
        self::downloadMacros();
        self::apiMacros();
    }

    private static function routeMacros(): void
    {
        // Règle RBAC de la route, lue par PermissionMiddleware et ApiPermissionMiddleware : une clé de
        // PermissionCatalog ('shifts.update'), un tableau ['perm' => clé, 'self' => 'user_id'] (ou 'membership' /
        // 'store_param'), ou 'public' pour une route volontairement exemptée de contrôle fin.
        Route::macro('permission', function (string|array $rule): Route {
            /** @var Route $this */
            return $this->setAction(array_merge($this->getAction(), [Routes::PERMISSION => $rule]));
        });

        // Paramètres typés : Route::get('/stores/{id}/edit', …)->bindSegments(['id' => 'store']). Le segment lisible
        // (slug, numéro d'employé) devient l'identifiant avant les autres middlewares de la route (BindRouteParameters).
        Route::macro('bindSegments', function (array $types): Route {
            /** @var Route $this */
            $action = array_merge($this->getAction(), [Routes::BINDINGS => $types]);
            $action['middleware'] = array_values(array_unique(array_merge(
                ['kintai.bind'],
                (array) ($action['middleware'] ?? []),
            )));

            return $this->setAction($action);
        });

        // Règle de permission et nom de la route de la requête (posés à la main par les tests de middlewares).
        Request::macro('routePermission', function (): string|array|null {
            /** @var Request $this */
            return $this->attributes->has('route_permission')
                ? $this->attributes->get('route_permission')
                : Routes::permission($this->route());
        });
        Request::macro('routeName', function (): ?string {
            /** @var Request $this */
            return $this->attributes->get('route_name') ?? $this->route()?->getName();
        });
    }

    private static function requestMacros(): void
    {
        // Chemin décodé commençant par « / » (les routes sont écrites ainsi).
        Request::macro('slashPath', function (): string {
            /** @var Request $this */
            return '/' . $this->decodedPath();
        });

        // Le champ est-il présent dans le corps POST (même vide) ?
        Request::macro('hasPost', function (string $key): bool {
            /** @var Request $this */
            return $this->request->has($key);
        });

        // Case à cocher d'un formulaire de modification : 1 cochée, 0 décochée (champ caché « 0 »), valeur actuelle si
        // le champ est absent (une requête partielle ne doit rien désactiver).
        Request::macro('postFlag', function (string $key, mixed $current): int {
            /** @var Request $this */
            if (!$this->request->has($key)) {
                return empty($current) ? 0 : 1;
            }
            return in_array($this->request->get($key), ['1', 'on', 'true', 1, true], true) ? 1 : 0;
        });

        // Appel d'API ou AJAX : chemin /api/, X-Requested-With, ou Accept qui contient application/json.
        Request::macro('wantsJsonResponse', function (): bool {
            /** @var Request $this */
            return str_starts_with('/' . $this->decodedPath(), '/api/')
                || $this->ajax()
                || str_contains((string) $this->header('Accept'), 'application/json');
        });
    }

    private static function downloadMacros(): void
    {
        $attachment = static function (SymfonyResponse $response, string $filename): SymfonyResponse {
            $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
            return $response;
        };

        ResponseFactory::macro('csv', function (string $body, string $filename) use ($attachment): SymfonyResponse {
            return $attachment(Response::make($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8']), $filename);
        });

        ResponseFactory::macro('jsonDownload', function (mixed $data, string $filename) use ($attachment): SymfonyResponse {
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            return $attachment(Response::make($body, 200, ['Content-Type' => 'application/json; charset=UTF-8']), $filename);
        });

        ResponseFactory::macro('fileContents', function (string $body, string $contentType, string $filename) use ($attachment): SymfonyResponse {
            return $attachment(Response::make($body, 200, [
                'Content-Type'   => $contentType,
                'Content-Length' => (string) strlen($body),
                'Cache-Control'  => 'no-cache, no-store',
            ]), $filename);
        });

        ResponseFactory::macro('pdf', function (string $body, string $filename) use ($attachment): SymfonyResponse {
            return $attachment(Response::make($body, 200, [
                'Content-Type'   => 'application/pdf',
                'Content-Length' => (string) strlen($body),
            ]), $filename);
        });

        ResponseFactory::macro('ical', function (string $body, string $filename = 'shifts.ics') use ($attachment): SymfonyResponse {
            return $attachment(Response::make($body, 200, [
                'Content-Type'  => 'text/calendar; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store',
            ]), $filename);
        });

        // Fichier envoyé tel quel, sans le charger en mémoire.
        ResponseFactory::macro('fileStream', function (string $path, string $contentType, string $filename) use ($attachment): SymfonyResponse {
            $response = new FileStreamResponse(static function () use ($path): void {
                readfile($path);
            }, 200, [
                'Content-Type'   => $contentType,
                'Content-Length' => (string) filesize($path),
            ]);
            return $attachment($response, $filename);
        });
    }

    private static function apiMacros(): void
    {
        ResponseFactory::macro('apiError', function (string $message, int $status = 400, ?string $code = null, array $errors = []): JsonResponse {
            $data = ['error' => $message];
            if ($code !== null) {
                $data['code'] = $code;
            }
            if ($errors !== []) {
                $data['errors'] = $errors;
            }
            return Response::json($data, $status, [], JSON_UNESCAPED_UNICODE);
        });

        ResponseFactory::macro('apiCreated', function (mixed $data): JsonResponse {
            return Response::json($data, 201, [], JSON_UNESCAPED_UNICODE);
        });

        ResponseFactory::macro('apiDeleted', function (): SymfonyResponse {
            return Response::noContent();
        });

        ResponseFactory::macro('apiSuccess', function (string $message, mixed $data = null, int $status = 200): JsonResponse {
            $payload = ['message' => $message];
            if ($data !== null) {
                $payload['data'] = $data;
            }
            return Response::json($payload, $status, [], JSON_UNESCAPED_UNICODE);
        });

        ResponseFactory::macro('apiPaginated', function (array $items, int $total, int $page, int $limit): JsonResponse {
            return Response::json(['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit], 200, [], JSON_UNESCAPED_UNICODE);
        });
    }
}
