<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Middleware;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Middleware\BundleViewDataMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Variables de vue des bundles (étape 2g du contrat de bundle) : déclarations des bundles actifs
 * (Bundle::viewVariables(), contrat 1.5), noms réservés, isolation ; plus aucun repli du Core.
 */
final class BundleViewDataMiddlewareTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /** @param array<string, mixed> $active slug => variables déclarées (array, ou \Throwable levée) */
    private function middleware(array $active): BundleViewDataMiddleware
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function viewVariables(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);
        $this->container->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        return new BundleViewDataMiddleware($this->container);
    }

    /** Aucun bundle ne déclare : aucune variable (plus de repli du Core pour daily-report ni messaging). */
    public function testNoDeclarationMeansNoVariable(): void
    {
        $this->assertSame([], $this->middleware(['messaging' => [], 'daily-report' => []])->variables(['id' => 7], false));
        $this->assertSame([], $this->middleware([])->variables(['id' => 7], false));
    }

    public function testDeclaredVariablesAreIsolated(): void
    {
        $mw = $this->middleware([
            'messaging' => ['unread_messages_count' => fn(array $u): int => 40 + $u['id']],
            'acme'      => [
                'acme_badge'      => fn(): string => 'ok',
                'auth_user'       => fn(): string => 'usurpé',
                'unread_messages_count' => fn(): int => 999,
                'acme_broken'     => fn() => throw new \RuntimeException('kaput'),
                'Bad Name'        => fn(): int => 1,
                'acme_not_closure' => 'valeur',
            ],
            'boom'      => new \RuntimeException('kaput'),
        ]);

        $vars = $mw->variables(['id' => 7], true);

        $this->assertSame(47, $vars['unread_messages_count']);
        $this->assertSame('ok', $vars['acme_badge']);
        $this->assertArrayNotHasKey('auth_user', $vars);
        $this->assertArrayNotHasKey('acme_broken', $vars);
        $this->assertArrayNotHasKey('acme_not_closure', $vars);
        $this->assertArrayNotHasKey('daily_report_staff_stores', $vars, 'daily-report inactif : rien');
    }
}
