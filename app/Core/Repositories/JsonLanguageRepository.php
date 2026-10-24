<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

final class JsonLanguageRepository implements LanguageRepositoryInterface
{
    private string $langPath;
    private string $manifestPath;

    public function __construct(string $langPath)
    {
        $this->langPath = rtrim($langPath, '/\\');
        $this->manifestPath = $this->langPath . '/languages.json';
    }

    public function findAll(): array
    {
        $langs = $this->load();
        usort($langs, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        return $langs;
    }

    public function findAllActive(): array
    {
        return array_values(array_filter($this->findAll(), fn($l) => !empty($l['is_active'])));
    }

    public function findByCode(string $code): ?array
    {
        foreach ($this->load() as $lang) {
            if (($lang['code'] ?? null) === $code) {
                return $lang;
            }
        }
        return null;
    }

    public function findDefault(): ?array
    {
        foreach ($this->load() as $lang) {
            if (!empty($lang['is_default'])) {
                return $lang;
            }
        }
        return null;
    }

    public function save(array $data): array
    {
        $langs = $this->load();
        $code  = (string) ($data['code'] ?? '');
        $found = false;

        foreach ($langs as &$lang) {
            if (($lang['code'] ?? null) === $code) {
                $lang  = array_merge($lang, $data);
                $found = true;
                break;
            }
        }
        unset($lang);

        if (!$found) {
            $data = array_merge([
                'is_active'  => true,
                'is_default' => false,
                'sort_order' => count($langs),
            ], $data);
            $langs[] = $data;
        }

        $this->write($langs);

        $translationFile = $this->langPath . '/' . $code . '.json';
        if ($code !== '' && !file_exists($translationFile)) {
            file_put_contents($translationFile, "{}\n");
        }

        return $this->findByCode($code) ?? $data;
    }

    public function setDefault(string $code): bool
    {
        $langs = $this->load();
        $exists = false;

        foreach ($langs as &$lang) {
            $isTarget = ($lang['code'] ?? null) === $code;
            $lang['is_default'] = $isTarget;
            if ($isTarget) {
                $exists = true;
            }
        }
        unset($lang);

        if (!$exists) {
            return false;
        }

        $this->write($langs);
        return true;
    }

    public function toggleActive(string $code, bool $active): bool
    {
        $langs = $this->load();
        $found = false;

        foreach ($langs as &$lang) {
            if (($lang['code'] ?? null) === $code) {
                $lang['is_active'] = $active;
                $found = true;
                break;
            }
        }
        unset($lang);

        if (!$found) {
            return false;
        }

        $this->write($langs);
        return true;
    }

    public function delete(string $code): int
    {
        $langs  = $this->load();
        $before = count($langs);
        $langs  = array_values(array_filter($langs, fn($l) => ($l['code'] ?? null) !== $code));

        if (count($langs) === $before) {
            return 0;
        }

        $this->write($langs);

        $translationFile = $this->langPath . '/' . $code . '.json';
        if (file_exists($translationFile)) {
            unlink($translationFile);
        }

        return 1;
    }

    private function load(): array
    {
        if (!file_exists($this->manifestPath)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->manifestPath), true);
        return is_array($data) ? $data : [];
    }

    private function write(array $languages): void
    {
        file_put_contents(
            $this->manifestPath,
            json_encode(array_values($languages), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL
        );
    }
}
