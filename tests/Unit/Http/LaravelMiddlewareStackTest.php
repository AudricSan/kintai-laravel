<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Http;

use Closure;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Pipeline\Pipeline;
use kintai\Core\Application as KintaiApplication;
use kintai\Core\Container;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\MiddlewareInterface;
use kintai\Core\Middleware\MiddlewarePipeline;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Foundation\Application as LaravelApplication;
use kintai\Http\KintaiRoutes;
use kintai\Http\Middleware\CarriedResponse;
use kintai\Http\Middleware\KintaiKernel;
use kintai\Http\Middleware\KintaiMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Les middlewares Kintai dans la pile de Laravel se comportent comme dans MiddlewarePipeline : même ordre, et une
 * exception remonte à travers eux (chacun peut l'intercepter) jusqu'au rendu des erreurs de Kintai.
 */
final class LaravelMiddlewareStackTest extends TestCase
{
    /** @var list<string> */
    public static array $trace = [];

    private ?Container $previous = null;
    private LaravelApplication $laravel;

    protected function setUp(): void
    {
        self::$trace = [];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/index.php', 'HTTP_ACCEPT' => 'application/json'];
        $this->previous = (new \ReflectionProperty(Container::class, 'instance'))->getValue();

        $this->laravel = new LaravelApplication(dirname(__DIR__, 3));
        $container = new Container($this->laravel);
        Container::setInstance($container);

        // Application Kintai sans démarrage : seuls son conteneur et sa pile servent ici.
        $app = (new \ReflectionClass(KintaiApplication::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(KintaiApplication::class, 'container'))->setValue($app, $container);
        (new \ReflectionProperty(KintaiApplication::class, 'pipeline'))->setValue($app, new MiddlewarePipeline($container));
        $this->laravel->instance(KintaiApplication::class, $app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        $_SERVER = [];
    }

    /** @param list<class-string> $middleware */
    private function through(array $middleware, Closure $controller): Response|\Symfony\Component\HttpFoundation\Response
    {
        $stack = [KintaiKernel::class];
        foreach ($middleware as $i => $class) {
            $stack[] = KintaiMiddleware::class . ':' . $class . ',' . $i;
        }

        return (new Pipeline($this->laravel))
            ->send(IlluminateRequest::create('/x'))
            ->through($stack)
            ->then(static function (IlluminateRequest $request) use ($controller) {
                try {
                    return CarriedResponse::of($controller(KintaiKernel::request($request)));
                } catch (\Throwable $e) {
                    return CarriedResponse::thrown($e);
                }
            });
    }

    public function testMiddlewaresRunInOrderAroundTheController(): void
    {
        $response = $this->through([TraceA::class, TraceB::class], static function (): Response {
            LaravelMiddlewareStackTest::$trace[] = 'controller';
            return Response::html('ok');
        });

        $this->assertSame(['A>', 'B>', 'controller', '<B', '<A'], self::$trace);
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('A', $response->headers->get('X-Trace'));
    }

    public function testAnExceptionCrossesTheMiddlewaresAndCanBeCaught(): void
    {
        $response = $this->through([TraceA::class, Recover::class, TraceB::class], static function (): Response {
            throw new \RuntimeException('boom');
        });

        // TraceB ne termine pas (l'exception le traverse), Recover la transforme en réponse, TraceA la voit passer.
        $this->assertSame(['A>', 'B>', 'recovered', '<A'], self::$trace);
        $this->assertSame(418, $response->getStatusCode());
    }

    public function testAnUncaughtExceptionIsRenderedByKintai(): void
    {
        $response = $this->through([TraceA::class], static function (): Response {
            throw new HttpException(403, 'Interdit');
        });

        $this->assertSame(['A>'], self::$trace);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEachKintaiMiddlewareEntryIsUnique(): void
    {
        $stack = KintaiRoutes::middleware([TraceA::class, TraceB::class], [TraceA::class]);

        $this->assertSame('kintai.kernel', $stack[0]);
        $this->assertSame('kintai.resolve', $stack[3]);
        $this->assertCount(5, array_unique($stack));
    }
}

final class TraceA implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        LaravelMiddlewareStackTest::$trace[] = 'A>';
        $response = $next($request);
        LaravelMiddlewareStackTest::$trace[] = '<A';
        return $response->withHeader('X-Trace', 'A');
    }
}

final class TraceB implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        LaravelMiddlewareStackTest::$trace[] = 'B>';
        $response = $next($request);
        LaravelMiddlewareStackTest::$trace[] = '<B';
        return $response;
    }
}

final class Recover implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (\RuntimeException) {
            LaravelMiddlewareStackTest::$trace[] = 'recovered';
            return Response::html('teapot', 418);
        }
    }
}
