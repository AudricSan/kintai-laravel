<?php

declare(strict_types=1);

namespace kintai\UI;

use RuntimeException;

final class ViewRenderer
{
    private string $basePath;

    /** @var array<string, string> Namespaced view paths */
    private array $namespaces = [];

    /** @var array<string, mixed> Shared data available in all views */
    private array $shared = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->shared[$key] ?? null;
    }

    /**
     * Registers a view namespace (e.g. 'messaging' -> '/path/to/bundle/views').
     */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace] = rtrim($path, '/\\');
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
        $file = $this->resolvePath($view);

        if (!file_exists($file)) {
            throw new RuntimeException("View [{$view}] not found at [{$file}].");
        }

        $allData = array_merge($this->shared, $data);

        ob_start();
        (static function (string $_file, array $_data) {
            extract($_data, EXTR_SKIP);
            include $_file;
        })($file, $allData);

        return ob_get_clean();
    }

    private function resolvePath(string $view): string
    {
        if (str_contains($view, '::')) {
            [$namespace, $viewName] = explode('::', $view, 2);
            if (!isset($this->namespaces[$namespace])) {
                throw new RuntimeException("View namespace [{$namespace}] not registered.");
            }
            $relative = str_replace('.', DIRECTORY_SEPARATOR, $viewName) . '.php';
            return $this->namespaces[$namespace] . DIRECTORY_SEPARATOR . $relative;
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        return $this->basePath . DIRECTORY_SEPARATOR . $relative;
    }
}
