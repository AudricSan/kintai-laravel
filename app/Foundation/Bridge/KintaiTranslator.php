<?php

declare(strict_types=1);

namespace kintai\Foundation\Bridge;

use Illuminate\Contracts\Translation\Translator;
use kintai\Core\Container;
use kintai\Core\Services\TranslationService;

/**
 * Traducteur Laravel (« translator ») adossé aux traductions de Kintai (TranslationService : lang/*.json et ceux des
 * bundles). __(), trans() et @lang de Laravel rendent ainsi exactement les textes de Kintai, avec les paramètres
 * « :nom ». Tant que le service n'est pas prêt (tout début du démarrage), la clé est renvoyée telle quelle.
 */
final class KintaiTranslator implements Translator
{
    public function get($key, array $replace = [], $locale = null): string
    {
        $service = $this->service();
        if ($service === null) {
            return (string) $key;
        }

        if ($locale === null || $locale === $service->getLocale()) {
            return $service->translate((string) $key, $replace);
        }

        $current = $service->getLocale();
        try {
            $service->setLocale((string) $locale);
            return $service->translate((string) $key, $replace);
        } finally {
            $service->setLocale($current);
        }
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return $this->get($key, ['count' => $number] + $replace, $locale);
    }

    public function getLocale(): string
    {
        return $this->service()?->getLocale() ?? 'ja';
    }

    public function setLocale($locale): void
    {
        $this->service()?->setLocale((string) $locale);
    }

    private function service(): ?TranslationService
    {
        try {
            $container = Container::getInstance();
            return $container->has(TranslationService::class) ? $container->make(TranslationService::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
