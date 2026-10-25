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
 * namespace messaging). Elle peut être une vue Blade (.blade.php), prioritaire à nom égal, ou une vue PHP (.php,
 * rendue comme avant, voir {@see KintaiPhpEngine}).
 *
 * En plus des directives de Blade : @includeInScope('_partials.x') insère une vue dans la portée de la vue courante,
 * comme le faisait un include PHP. Elle lit et peut définir les variables de la vue appelante ; @include, lui, ne lui
 * transmet qu'une copie.
 */
final class ViewRenderer
{
    private string $basePath;

    private Factory $factory;

    /** Moteur de la vue en cours de rendu : celui des vues insérées par @includeInScope, même depuis une fonction. */
    private static ?Factory $current = null;

    /** Dernier moteur utilisé, à défaut de vue en cours de rendu et de ViewRenderer de l'application. */
    private static ?Factory $lastUsed = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $files   = new Filesystem();
        $engines = new EngineResolver();
        $engines->register('php', fn() => new KintaiPhpEngine($files));
        $engines->register('blade', fn() => new KintaiBladeEngine(self::bladeCompiler($files), $files));

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

        $previous       = self::$current;
        self::$current  = $this->factory;
        self::$lastUsed = $this->factory;
        try {
            return $this->factory->file($file, $data)->render();
        } finally {
            self::$current = $previous;
        }
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

    /**
     * Fichier à inclure pour insérer la vue $name dans la portée courante (@includeInScope) : la vue compilée pour une
     * vue Blade, le fichier lui-même pour une vue PHP.
     */
    public static function pathInScope(string $name, ?Factory $env = null): string
    {
        $env ??= self::current();
        self::$lastUsed = $env;
        $path   = $env->getFinder()->find($name);
        $engine = $env->getEngineFromPath($path);
        if (!$engine instanceof CompilerEngine) {
            return $path;
        }

        $compiler = $engine->getCompiler();
        if ($compiler->isExpired($path)) {
            $compiler->compile($path);
        }

        return $compiler->getCompiledPath($path);
    }

    /** Moteur de la vue en cours de rendu. */
    public static function current(): Factory
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $container = \kintai\Core\Container::getInstance();

        return $container->has(self::class) || self::$lastUsed === null
            ? $container->make(self::class)->factory()
            : self::$lastUsed;
    }

    private static function bladeCompiler(Filesystem $files): BladeCompiler
    {
        $compiler = new BladeCompiler($files, self::compiledPath());
        $compiler->directive(
            'includeInScope',
            static fn(string $expression): string => '<?php include \\' . self::class . '::pathInScope(' . $expression . '); ?>',
        );
        // Une vue insérée depuis une fonction (tampon ob_start() dans une closure…) n'a pas $__env, dont Blade a
        // besoin (@foreach…). Le saut de ligne qui suit la balise est mangé par PHP : la sortie reste identique.
        $compiler->precompiler(
            static fn(string $value): string => '<?php $__env ??= \\' . self::class . "::current(); ?>\n" . $value,
        );

        return $compiler;
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
