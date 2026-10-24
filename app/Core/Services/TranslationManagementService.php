<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\TranslationRepositoryInterface;

final class TranslationManagementService
{
    public function __construct(
        private readonly TranslationRepositoryInterface $translations,
    ) {}

    /**
     * @return array{items: list<array{key:string,reference:string,value:string}>, total:int}
     */
    public function browseKeys(string $locale, string $search = '', int $page = 1, int $perPage = 50): array
    {
        $allKeys = $this->translations->findAllKeys();
        sort($allKeys);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $allKeys = array_values(array_filter(
                $allKeys,
                fn($k) => str_contains(mb_strtolower($k), $needle)
            ));
        }

        $total    = count($allKeys);
        $page     = max(1, $page);
        $pageKeys = array_slice($allKeys, ($page - 1) * $perPage, $perPage);

        $localeValues    = $this->translations->findByLocale($locale);
        $referenceValues = $this->translations->findByLocale('en');

        $items = [];
        foreach ($pageKeys as $key) {
            $items[] = [
                'key'       => $key,
                'reference' => $referenceValues[$key] ?? '',
                'value'     => $localeValues[$key] ?? '',
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function saveValue(string $locale, string $key, string $value): void
    {
        $this->translations->save($locale, $key, $value);
    }

    public function deleteValue(string $locale, string $key): int
    {
        return $this->translations->delete($locale, $key);
    }
}
