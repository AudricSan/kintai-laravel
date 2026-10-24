<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\PublicUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL publique de l'instance, base des liens absolus des e-mails. Priorité : APP_URL, puis réglage Owner ;
 * jamais l'en-tête Host de la requête (qu'un attaquant choisit).
 */
final class PublicUrlResolverTest extends TestCase
{
    private function resolver(string $setting = '', string $env = ''): PublicUrlResolver
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($setting === '' ? [] : [PublicUrlResolver::SETTING_KEY => $setting]);

        return new PublicUrlResolver(new AppSettingsService($repo), $env);
    }

    /** @return array<string, array{string, string}> */
    public static function validUrls(): array
    {
        return [
            'domaine https'            => ['https://kintai.example.com', 'https://kintai.example.com'],
            'slash final retiré'       => ['https://kintai.example.com/', 'https://kintai.example.com'],
            'sous-dossier'             => ['https://example.com/kintai/', 'https://example.com/kintai'],
            'port'                     => ['http://127.0.0.7:8080', 'http://127.0.0.7:8080'],
            'casse du domaine'         => ['HTTPS://Kintai.Example.COM/App', 'https://kintai.example.com/App'],
            'espaces autour'           => ['  https://kintai.test  ', 'https://kintai.test'],
        ];
    }

    #[DataProvider('validUrls')]
    public function testValidUrlsAreNormalized(string $input, string $expected): void
    {
        $this->assertSame($expected, PublicUrlResolver::normalize($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return [
            'vide'                      => [''],
            'chemin seul (ancien bug)'  => ['/Kintai'],
            'sans schéma'               => ['kintai.example.com'],
            'schéma javascript'         => ['javascript:alert(1)'],
            'ftp'                       => ['ftp://example.com'],
            'identifiants'              => ['https://user:pass@example.com'],
            'requête'                   => ['https://example.com/?next=evil'],
            'fragment'                  => ['https://example.com/#x'],
            'domaine exotique'          => ['https://exa mple.com'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $input): void
    {
        $this->assertNull(PublicUrlResolver::normalize($input));
    }

    public function testEnvironmentTakesPrecedenceOverTheOwnerSetting(): void
    {
        $resolver = $this->resolver('https://from-setting.example.com', 'https://from-env.example.com/');

        $this->assertSame('https://from-env.example.com', $resolver->resolve());
        $this->assertTrue($resolver->isForcedByEnvironment());
    }

    public function testFallsBackToTheOwnerSetting(): void
    {
        $resolver = $this->resolver('https://from-setting.example.com');

        $this->assertSame('https://from-setting.example.com', $resolver->resolve());
        $this->assertFalse($resolver->isForcedByEnvironment());
    }

    public function testAnInvalidEnvValueDoesNotHideAValidSetting(): void
    {
        $this->assertSame('https://ok.example.com', $this->resolver('https://ok.example.com', 'not a url')->resolve());
    }

    public function testNothingConfiguredGivesNull(): void
    {
        $this->assertNull($this->resolver()->resolve());
    }

    public function testTheRequestHostHeaderIsNeverUsed(): void
    {
        // Un attaquant qui demande une réinitialisation contrôle cet en-tête : il ne doit jamais servir de base.
        $_SERVER['HTTP_HOST'] = 'evil.example.net';
        try {
            $this->assertNull($this->resolver()->resolve());
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
    }
}
