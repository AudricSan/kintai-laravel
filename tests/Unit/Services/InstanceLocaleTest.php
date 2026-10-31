<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EnvFileWriter;
use App\Services\InstanceLocale;
use PHPUnit\Framework\TestCase;

final class InstanceLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);
        putenv('APP_LOCALE');
    }

    public function testConfiguredLanguageWins(): void
    {
        $_ENV['APP_LOCALE'] = 'en';
        kintai_test_languages(['fr', 'en', 'ja']);
        $this->assertSame('en', InstanceLocale::resolve());
    }

    public function testWithoutTheVariableTheFirstActiveLanguageIsUsed(): void
    {
        kintai_test_languages(['ja', 'fr', 'en']);
        $this->assertSame('ja', InstanceLocale::resolve());
    }

    /** Nouvelle instance (installateur web, kintai:provision) : l'anglais quand il est disponible. */
    public function testANewInstanceDefaultsToEnglish(): void
    {
        $this->assertSame('en', InstanceLocale::defaultForNewInstance(['fr', 'en', 'ja']));
    }

    public function testANewInstanceWithoutEnglishTakesTheFirstLanguage(): void
    {
        $this->assertSame('fr', InstanceLocale::defaultForNewInstance(['fr', 'ja']));
    }

    public function testAnInactiveConfiguredLanguageIsIgnored(): void
    {
        $_ENV['APP_LOCALE'] = 'de';
        kintai_test_languages(['fr', 'en']);
        $this->assertSame('fr', InstanceLocale::resolve());
        kintai_test_languages([]);
        $this->assertSame('en', InstanceLocale::resolve());
    }

    public function testAUserWithoutAnActiveLanguageFollowsTheInstance(): void
    {
        $_ENV['APP_LOCALE'] = 'ja';
        kintai_test_languages(['fr', 'ja']);
        $locale = new InstanceLocale();

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
            kintai_test_languages(['fr', 'ja']);
            $locale = new InstanceLocale(new EnvFileWriter($dir));
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
