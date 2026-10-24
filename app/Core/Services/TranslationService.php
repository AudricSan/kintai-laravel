<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;

final class TranslationService
{
    private string $locale = 'en';
    private array $translations = [];
    private array $fallbackTranslations = [];

    public function __construct(
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly LanguageRepositoryInterface $languageRepository,
    ) {
    }

    public function setLocale(string $locale): void
    {
        if ($this->locale === $locale && !empty($this->translations)) {
            return;
        }

        $this->locale = $locale;
        $this->translations = $this->translationRepository->findByLocale($locale);

        $defaultCode = InstanceLocale::resolve($this->languageRepository);
        $this->fallbackTranslations = $defaultCode !== $locale
            ? $this->translationRepository->findByLocale($defaultCode)
            : [];
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function translate(string $key, array $replace = []): string
    {
        $translation = $this->translations[$key] ?? $this->fallbackTranslations[$key] ?? $key;

        foreach ($replace as $search => $value) {
            $translation = str_replace(':' . $search, (string) $value, $translation);
        }

        return $translation;
    }
}
