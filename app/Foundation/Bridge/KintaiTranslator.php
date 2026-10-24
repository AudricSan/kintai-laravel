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
 *
 * Messages du validateur de Laravel (validation.required, validation.max.string…) : sans traduction propre, ils
 * prennent les messages de validation de Kintai (val_field_*), dans la langue de l'utilisateur.
 */
final class KintaiTranslator implements Translator
{
    /** Règle Laravel → message de Kintai ; toute autre règle donne val_field_invalid. */
    private const VALIDATION_MESSAGES = [
        'required'             => 'val_field_required',
        'required_if'          => 'val_field_required',
        'required_unless'      => 'val_field_required',
        'required_with'        => 'val_field_required',
        'required_with_all'    => 'val_field_required',
        'required_without'     => 'val_field_required',
        'required_without_all' => 'val_field_required',
        'present'              => 'val_field_required',
        'filled'               => 'val_field_required',
        'max.string'           => 'val_field_too_long',
        'max.array'            => 'val_field_too_long',
        'max.file'             => 'val_field_too_long',
        'max.numeric'          => 'val_field_out_of_range',
        'min.numeric'          => 'val_field_out_of_range',
        'between.numeric'      => 'val_field_out_of_range',
        'gt.numeric'           => 'val_field_out_of_range',
        'gte.numeric'          => 'val_field_out_of_range',
        'lt.numeric'           => 'val_field_out_of_range',
        'lte.numeric'          => 'val_field_out_of_range',
        'in'                   => 'val_field_out_of_range',
        'not_in'               => 'val_field_out_of_range',
    ];

    public function get($key, array $replace = [], $locale = null): string
    {
        $service = $this->service();
        if ($service === null) {
            return (string) $key;
        }

        $key         = (string) $key;
        $translation = $this->translate($service, $key, $replace, $locale);
        if ($translation === $key && str_starts_with($key, 'validation.')) {
            return $this->validationMessage($service, $key, $locale) ?? $key;
        }

        return $translation;
    }

    /** @param array<string, mixed> $replace */
    private function translate(TranslationService $service, string $key, array $replace, mixed $locale): string
    {
        if ($locale === null || $locale === $service->getLocale()) {
            return $service->translate($key, $replace);
        }

        $current = $service->getLocale();
        try {
            $service->setLocale((string) $locale);
            return $service->translate($key, $replace);
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

    /**
     * Message de Kintai pour une règle du validateur de Laravel, avec les paramètres de Laravel (:attribute, :max,
     * :min), que le validateur remplace ensuite. Null pour une clé qui n'est pas un message de règle.
     */
    private function validationMessage(TranslationService $service, string $key, mixed $locale): ?string
    {
        $rule = substr($key, strlen('validation.'));
        if ($rule === '' || preg_match('/^(attributes|custom|values)(\.|$)/', $rule) === 1) {
            return null;
        }

        return $this->translate(
            $service,
            self::VALIDATION_MESSAGES[$rule] ?? 'val_field_invalid',
            ['field' => ':attribute'],
            $locale,
        );
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
