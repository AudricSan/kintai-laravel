<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\PublicUrlResolver;

/**
 * Organisation : nom affiché sous « Kintai » (aussi nom de l'agenda externe), e-mail de support et adresse publique
 * (nécessaire aux e-mails, au push et aux liens signés). Mêmes réglages et mêmes règles que « Réglages Owner ».
 */
final class OrganisationStep implements SetupStepInterface
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly PublicUrlResolver $publicUrl,
    ) {}

    public function id(): string
    {
        return 'organisation';
    }

    public function data(Request $request): array
    {
        // Suggestion jamais enregistrée sans action : l'adresse par laquelle l'Owner consulte cette page.
        $suggestion = PublicUrlResolver::normalize(
            ($request->isSecure() ? 'https' : 'http') . '://' . (string) $request->server('HTTP_HOST', '') . rtrim(base_url(), '/')
        );
        return [
            'name'           => $this->settings->subtitle(),
            'support_email'  => $this->settings->supportEmail(),
            'public_url'     => $this->settings->get(PublicUrlResolver::SETTING_KEY),
            'public_url_forced' => $this->publicUrl->isForcedByEnvironment(),
            'public_url_suggestion' => $suggestion,
        ];
    }

    public function handle(Request $request, int $userId): array
    {
        $name  = substr(trim((string) $request->post('name', '')), 0, 100);
        $email = trim((string) $request->post('support_email', ''));
        $url   = trim((string) $request->post('public_url', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'setup_org_name_required';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'setup_org_email_invalid';
        }
        $normalizedUrl = $url === '' ? '' : PublicUrlResolver::normalize($url);
        if ($normalizedUrl === null) {
            $errors[] = 'public_url_invalid';
        }
        if ($errors !== []) {
            return $errors;
        }

        $values = ['app_subtitle' => $name, 'app_support_email' => $email];
        if (!$this->publicUrl->isForcedByEnvironment()) {
            $values[PublicUrlResolver::SETTING_KEY] = (string) $normalizedUrl;
        }
        $this->settings->setMany($values);
        return [];
    }

    public function summary(): ?string
    {
        $name = $this->settings->subtitle();
        return $name === '' ? null : $name;
    }
}
