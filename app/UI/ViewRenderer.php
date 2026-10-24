<?php

declare(strict_types=1);

namespace kintai\UI;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;
use RuntimeException;

/**
 * Rendu des vues de Kintai et de ses bundles, sur le moteur de vues de Laravel.
 *
 * Une vue se désigne en notation pointée (« auth.login » → auth/login.php, « messaging::inbox » → la vue inbox du
 * namespace messaging). Elle peut être une vue PHP (.php, rendue comme avant, voir {@see KintaiPhpEngine}) ou une vue
 * Blade (.blade.php), prioritaire à nom égal.
 */
final class ViewRenderer
{
    private string $basePath;

    private Factory $factory;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $files   = new Filesystem();
        $engines = new EngineResolver();
        $engines->register('php', fn() => new KintaiPhpEngine($files));
        $engines->register('blade', fn() => new CompilerEngine(new BladeCompiler($files, self::compiledPath()), $files));

        $this->factory = new Factory($engines, new FileViewFinder($files, [$this->basePath], ['blade.php', 'php']), new Dispatcher());
    }

    /** Le moteur de vues Laravel qui rend les vues de ce ViewRenderer. */
    public function factory(): Factory
    {
        return $this->factory;
    }

    public function share(string $key, mixed $value): void
    {
        $this->factory->share($key, $value);
    }

    public function get(string $key): mixed
    {
        return $this->factory->shared($key);
    }

    /**
     * Registers a view namespace (e.g. 'messaging' -> '/path/to/bundle/views').
     */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->factory->replaceNamespace($namespace, rtrim($path, '/\\'));
    }

    /**
     * Render a view template and return the HTML string.
     *
     * @param string $view Dot-notation view name (e.g. "auth.login" -> auth/login.php)
     * @param array<string, mixed> $data Variables available in the view
     * @param string|null $layout Layout file (e.g. "layout.app" -> layout/app.php). Null for no layout.
     */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderPartial($view, $data);

        if ($layout !== null) {
            $content = $this->renderPartial($layout, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    /**
     * Render a single template file without layout.
     */
    public function renderPartial(string $view, array $data = []): string
    {
        try {
            $file = $this->factory->getFinder()->find($view);
        } catch (InvalidArgumentException) {
            throw $this->notFound($view);
        }

        return $this->factory->file($file, $data)->render();
    }

    private function notFound(string $view): RuntimeException
    {
        $viewName = $view;
        $base     = $this->basePath;
        if (str_contains($view, '::')) {
            [$namespace, $viewName] = explode('::', $view, 2);
            $hints = $this->factory->getFinder()->getHints();
            if (!isset($hints[$namespace])) {
                return new RuntimeException("View namespace [{$namespace}] not registered.");
            }
            $base = $hints[$namespace][0];
        }
        $file = $base . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $viewName) . '.php';

        return new RuntimeException("View [{$view}] not found at [{$file}].");
    }

    /** Dossier des vues Blade compilées (storage/framework/views). */
    private static function compiledPath(): string
    {
        $path = storage_path('framework/views');
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
