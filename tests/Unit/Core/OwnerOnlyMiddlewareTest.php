<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Middleware\OwnerOnlyMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
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
        return fn(Request $r) => Response::html('ok');
    }

    public function testAllowsOwner(): void
    {
        $request = new Request();
        $request->setAttribute('auth_user', ['id' => 1, 'is_admin' => 1]);

        $response = $this->middleware->handle($request, $this->next());

        $this->assertSame(200, $response->status());
    }

    public function testRejectsNonOwner(): void
    {
        $request = new Request();
        $request->setAttribute('auth_user', ['id' => 10, 'is_admin' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, $this->next());
    }

    public function testRejectsMissingAuthUser(): void
    {
        $request = new Request();

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, $this->next());
    }
}
