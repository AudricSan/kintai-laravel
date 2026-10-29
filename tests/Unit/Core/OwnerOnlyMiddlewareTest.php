<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Exceptions\ForbiddenException;
use App\Http\Middleware\OwnerOnlyMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\TestCase;

/**
 * Remplace les 7 méthodes requireOwner() dupliquées à l'identique dans
 * AdminRoleController, BackupController, DocsController, AppResetController,
 * BundleSettingsController, LanguageController, OwnerSettingsController
 * (RBAC-V2) — un seul middleware, posé sur la route plutôt que réimplémenté
 * par chaque contrôleur.
 */
final class OwnerOnlyMiddlewareTest extends TestCase
{
    private OwnerOnlyMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new OwnerOnlyMiddleware();
    }

    private function next(): \Closure
    {
        return fn(Request $r) => response('ok');
    }

    public function testAllowsOwner(): void
    {
        $request = Request::capture();
        $request->attributes->set('auth_user', ['id' => 1, 'is_admin' => 1]);

        $response = $this->middleware->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectsNonOwner(): void
    {
        $request = Request::capture();
        $request->attributes->set('auth_user', ['id' => 10, 'is_admin' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, $this->next());
    }

    public function testRejectsMissingAuthUser(): void
    {
        $request = Request::capture();

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, $this->next());
    }
}
