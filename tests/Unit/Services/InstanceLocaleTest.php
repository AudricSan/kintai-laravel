<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Services\EnvFileWriter;
use kintai\Core\Services\InstanceLocale;
use PHPUnit\Framework\TestCase;

final class InstanceLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);
        putenv('APP_LOCALE');
    }

    private function languages(array $active, ?string $legacyDefault = null): LanguageRepositoryInterface
    {
        $repo = $this->createStub(LanguageRepositoryInterface::class);
        $repo->method('findAllActive')->willReturn(array_map(static fn(string $c): array => ['code' => $c, 'name' => $c], $active));
        $repo->method('findDefault')->willReturn($legacyDefault === null ? null : ['code' => $legacyDefault]);
        return $repo;
    }

    public function testConfiguredLanguageWins(): void
    {
        $_ENV['APP_LOCALE'] = 'en';
        $this->assertSame('en', InstanceLocale::resolve($this->languages(['fr', 'en', 'ja'], 'ja')));
    }

    public function testExistingInstanceWithoutTheVariableKeepsItsLegacyDefault(): void
    {
        $this->assertSame('ja', InstanceLocale::resolve($this->languages(['fr', 'en', 'ja'], 'ja')));
    }

    /** Nouvelle instance (installateur web, provision.php) : anglais, jamais le is_default historique. */
    public function testANewInstanceDefaultsToEnglish(): void
    {
        $this->assertSame('en', InstanceLocale::defaultForNewInstance($this->languages(['fr', 'en', 'ja'], 'ja')));
    }

    public function testANewInstanceWithoutEnglishTakesTheFirstActiveLanguage(): void
    {
        $this->assertSame('fr', InstanceLocale::defaultForNewInstance($this->languages(['fr', 'ja'], 'ja')));
    }

    public function testAnInactiveConfiguredLanguageIsIgnored(): void
    {
        $_ENV['APP_LOCALE'] = 'de';
        $this->assertSame('fr', InstanceLocale::resolve($this->languages(['fr', 'en'], 'fr')));
        $this->assertSame('fr', InstanceLocale::resolve($this->languages(['fr', 'en'])));
        $this->assertSame('en', InstanceLocale::resolve($this->languages([])));
    }

    public function testAUserWithoutAnActiveLanguageFollowsTheInstance(): void
    {
        $_ENV['APP_LOCALE'] = 'ja';
        $locale = new InstanceLocale($this->languages(['fr', 'ja']));

        $this->assertSame('fr', $locale->forUser(['language' => 'fr']));
        $this->assertSame('ja', $locale->forUser(['language' => '']));
        $this->assertSame('ja', $locale->forUser(['language' => 'de']));
        $this->assertSame('ja', $locale->forUser(null));
    }

    public function testSetWritesTheEnvironmentFileAndRefusesAnInactiveLanguage(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-locale-' . uniqid();
        mkdir($dir);
        try {
            $locale = new InstanceLocale($this->languages(['fr', 'ja']), new EnvFileWriter($dir));
            $locale->set('fr');

            $this->assertSame("APP_LOCALE=fr\n", file_get_contents($dir . '/.env'));
            $this->assertSame('fr', $locale->code());

            $this->expectException(\InvalidArgumentException::class);
            $locale->set('de');
        } finally {
            @unlink($dir . '/.env');
            @rmdir($dir);
        }
    }
}
