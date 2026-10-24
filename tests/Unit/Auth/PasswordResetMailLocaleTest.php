<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use kintai\Core\Container;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\PasswordResetService;
use kintai\Core\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * L'e-mail de réinitialisation était écrit en français en dur (sujet et corps). Il est désormais traduit, dans la
 * langue du destinataire, et la langue de la requête en cours est rétablie ensuite.
 */
final class PasswordResetMailLocaleTest extends TestCase
{
    private TranslationService $translator;
    private PasswordResetService $service;

    protected function setUp(): void
    {
        $catalog = [
            'fr' => ['reset_mail_subject' => 'Sujet FR', 'reset_mail_title' => 'Titre FR', 'reset_mail_greeting' => 'Bonjour :name,'],
            'ja' => ['reset_mail_subject' => '件名 JA', 'reset_mail_title' => 'タイトル JA', 'reset_mail_greeting' => ':name 様'],
        ];
        $translations = $this->createStub(TranslationRepositoryInterface::class);
        $translations->method('findByLocale')->willReturnCallback(fn(string $l) => $catalog[$l] ?? []);
        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);

        $this->translator = new TranslationService($translations, $languages);
        $this->translator->setLocale('fr');
        // __() passe par le conteneur : on y place ce traducteur le temps du test.
        Container::getInstance()->instance(TranslationService::class, $this->translator);

        $this->service = new PasswordResetService(
            $this->createStub(PasswordResetRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            new MailerService(['driver' => 'native', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
            null,
            $this->translator,
        );
    }

    protected function tearDown(): void
    {
        // Les autres tests supposent qu'aucun traducteur n'est enregistré (__() renvoie alors la clé).
        $instances = new \ReflectionProperty(Container::class, 'instances');
        $instances->setAccessible(true);
        $current = $instances->getValue(Container::getInstance());
        unset($current[TranslationService::class]);
        $instances->setValue(Container::getInstance(), $current);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($this->service, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    public function testTheMailIsWrittenInTheRecipientsLanguage(): void
    {
        [$subject, $body] = $this->call('inLocale', 'ja', fn(): array => [
            __('reset_mail_subject'),
            $this->call('buildMailBody', 'Aiko', 'https://kintai.example.com/reset-password/abc'),
        ]);

        $this->assertSame('件名 JA', $subject);
        $this->assertStringContainsString('タイトル JA', $body);
        $this->assertStringContainsString('Aiko 様', $body);
        $this->assertStringContainsString('lang="ja"', $body);
        $this->assertStringNotContainsString('Réinitialisation', $body, 'plus de français en dur');
    }

    public function testTheRequestLanguageIsRestoredAfterwards(): void
    {
        $this->call('inLocale', 'ja', fn() => null);

        $this->assertSame('fr', $this->translator->getLocale());
        $this->assertSame('Sujet FR', __('reset_mail_subject'));
    }

    public function testWithoutAKnownLanguageTheCurrentOneIsKept(): void
    {
        $this->assertSame('Sujet FR', $this->call('inLocale', null, fn(): string => __('reset_mail_subject')));
    }

    public function testTheRecipientsNameIsEscaped(): void
    {
        $body = $this->call('buildMailBody', '<b>Aiko</b>', 'https://kintai.example.com/reset-password/abc');

        $this->assertStringContainsString('&lt;b&gt;Aiko&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>Aiko</b>', $body);
    }
}
