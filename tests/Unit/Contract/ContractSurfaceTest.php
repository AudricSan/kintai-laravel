<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Contract;

use kintai\Core\Container;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Tests\Support\ContractUsageScanner;
use PHPUnit\Framework\TestCase;

/**
 * Surface du contrat de bundle (app/Contract/surface.php) : chaque nom du contrat est un alias stable vers une
 * classe du Core, déclaré dès le démarrage, et le conteneur résout les alias.
 */
final class ContractSurfaceTest extends TestCase
{
    /** @return array<string, array{target: string, status: string, role: string}> */
    private static function surface(): array
    {
        return require dirname(__DIR__, 3) . '/app/Contract/surface.php';
    }

    public function testEveryEntryAliasesItsCoreClass(): void
    {
        foreach (self::surface() as $alias => $entry) {
            $this->assertTrue(
                class_exists($alias) || interface_exists($alias) || trait_exists($alias),
                "$alias ne se charge pas (fichier d'alias manquant ?)"
            );
            $this->assertSame($entry['target'], (new \ReflectionClass($alias))->getName(), $alias);
            $this->assertContains($entry['status'], ['stable', 'provisoire'], $alias);
            $this->assertNotSame('', trim($entry['role']), $alias);
        }
    }

    /** Déclarés dès le démarrage : PHP ne charge pas une classe pour vérifier un type de paramètre. */
    public function testEveryNameIsDeclaredWithoutAutoloading(): void
    {
        foreach (array_keys(self::surface()) as $alias) {
            $this->assertTrue(
                class_exists($alias, false) || interface_exists($alias, false) || trait_exists($alias, false),
                "$alias n'est pas déclaré au démarrage"
            );
        }
    }

    public function testATypeDeclaredWithAContractNameAcceptsTheCoreInstance(): void
    {
        $accept = static fn(\kintai\Contract\Http\Response $r): \kintai\Contract\Http\Response => $r;

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $accept(response()->noContent()));
    }

    public function testTheContainerResolvesAnAliasToTheSameBinding(): void
    {
        $container = new Container();
        $users = $this->createStub(UserRepositoryInterface::class);
        $container->instance(UserRepositoryInterface::class, $users);

        $this->assertTrue($container->has('kintai\\Contract\\Data\\UserRepositoryInterface'));
        $this->assertSame($users, $container->make('kintai\\Contract\\Data\\UserRepositoryInterface'));
    }

    public function testTheContainerAutowiresAConstructorTypedWithAnAlias(): void
    {
        $container = new Container();
        $users = $this->createStub(UserRepositoryInterface::class);
        $container->instance(UserRepositoryInterface::class, $users);

        $consumer = $container->make(ContractConsumerFixture::class);

        $this->assertSame($users, $consumer->users);
    }

    /** Les routes d'un bundle nomment leurs middlewares par le contrat : le routeur garde le nom réel de la classe. */
    public function testTheRouterStoresTheRealMiddlewareClass(): void
    {
        $router = kintai_test_registrar();
        $router->get('/demo', [self::class, 'x'], [\kintai\Contract\Http\Middleware\AuthMiddleware::class]);

        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/demo', 'GET'));

        $this->assertSame([\kintai\Core\Middleware\AuthMiddleware::class], $route->gatherMiddleware());
    }

    public function testTheScannerFlagsCoreClassesOutsideThePendingList(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-contract-scan-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/A.php', "<?php\nuse kintai\\Contract\\Http\\Request;\nuse kintai\\Core\\Request as R;\nuse kintai\\Core\\Repositories\\TimeoffRequestRepositoryInterface;\n\$x = new \\kintai\\UI\\Components\\Card();\n");
        try {
            $violations = ContractUsageScanner::violations([$dir], ['kintai\\Core\\Repositories\\TimeoffRequestRepositoryInterface']);
        } finally {
            @unlink($dir . '/A.php');
            @rmdir($dir);
        }

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('kintai\\Core\\Request', $violations[0]);
        $this->assertStringContainsString('kintai\\UI\\Components\\Card', $violations[1]);
    }
}

/** Consommateur typé avec un nom du contrat, comme le contrôleur d'un bundle. */
final class ContractConsumerFixture
{
    public function __construct(public readonly \kintai\Contract\Data\UserRepositoryInterface $users) {}
}
