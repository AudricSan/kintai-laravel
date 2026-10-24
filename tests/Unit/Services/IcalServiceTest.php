<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Container;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Services\IcalService;
use kintai\Core\Services\TranslationService;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * IcalService::build() doit produire un contenu traduit dans la langue de
 * l'employé propriétaire du flux (user['language']), avec repli sur la
 * locale du store puis 'fr' — même pattern que DailyReportMailService.
 *
 * build() utilise le helper global __() (voir app/helpers.php), qui résout
 * TranslationService via le Container plutôt que via l'instance injectée
 * directement : il faut donc lier cette même instance au Container le temps
 * du test (comme BackupControllerTest), sans quoi __() retombe silencieusement
 * sur la clé brute faute de TranslationService dans le container.
 */
final class IcalServiceTest extends TestCase
{
    private TranslationService $translations;
    private IcalService $service;

    protected function setUp(): void
    {
        $this->translations = new TranslationService(
            new JsonTranslationRepository(BASE_PATH . '/lang', BASE_PATH . '/app/Bundles'),
            new JsonLanguageRepository(BASE_PATH . '/lang'),
        );
        Container::getInstance()->instance(TranslationService::class, $this->translations);

        $this->service = new IcalService($this->translations);
    }

    protected function tearDown(): void
    {
        $instancesProp = new \ReflectionProperty(Container::class, 'instances');
        $instancesProp->setAccessible(true);
        $instances = $instancesProp->getValue(Container::getInstance());
        unset($instances[TranslationService::class]);
        $instancesProp->setValue(Container::getInstance(), $instances);
    }

    private function store(): array
    {
        return ['id' => 1, 'name' => 'Boutique Centrale', 'timezone' => 'Europe/Paris'];
    }

    private function timeoff(): array
    {
        return ['id' => 42, 'start_date' => '2026-09-20', 'end_date' => '2026-09-22'];
    }

    private function shift(): array
    {
        return [
            'id'            => 7,
            'shift_date'    => '2026-09-21',
            'start_time'    => '09:00',
            'end_time'      => '17:00',
            'pause_minutes' => 30,
        ];
    }

    /** Langue de l'instance (APP_LOCALE) le temps d'un test. */
    private function withInstanceLocale(string $code, callable $fn): void
    {
        $previous = $_ENV['APP_LOCALE'] ?? null;
        $_ENV['APP_LOCALE'] = $code;
        try {
            $fn();
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_LOCALE']);
            } else {
                $_ENV['APP_LOCALE'] = $previous;
            }
        }
    }

    public function testBuildUsesTheInstanceLanguageWhenTheUserHasNone(): void
    {
        $user = ['id' => 1, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'language' => ''];
        $this->withInstanceLocale('fr', function () use ($user): void {
            $content = $this->service->build([], [$this->timeoff()], $this->store(), $user);
            $this->assertStringContainsString('SUMMARY:Congé — Boutique Centrale', $content);
        });
        $this->withInstanceLocale('en', function () use ($user): void {
            $content = $this->service->build([], [$this->timeoff()], $this->store(), $user);
            $this->assertStringContainsString('SUMMARY:Leave — Boutique Centrale', $content);
        });
    }

    public function testBuildProducesEnglishSummaryWhenUserLanguageIsEnglish(): void
    {
        $user    = ['id' => 2, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $content = $this->service->build([], [$this->timeoff()], $this->store(), $user);

        $this->assertStringContainsString('SUMMARY:Leave — Boutique Centrale', $content);
        $this->assertStringNotContainsString('Congé', $content);
    }

    public function testBuildProducesJapaneseSummaryWhenUserLanguageIsJapanese(): void
    {
        $user    = ['id' => 3, 'first_name' => '太郎', 'last_name' => '山田', 'language' => 'ja'];
        $content = $this->service->build([], [$this->timeoff()], $this->store(), $user);

        $this->assertStringContainsString('SUMMARY:休暇 — Boutique Centrale', $content);
    }

    public function testBuildFallsBackToStoreLocaleWhenUserHasNoLanguage(): void
    {
        $user    = ['id' => 4, 'first_name' => 'John', 'last_name' => 'Smith'];
        $store   = $this->store() + ['locale' => 'en'];
        $content = $this->service->build([], [$this->timeoff()], $store, $user);

        $this->assertStringContainsString('SUMMARY:Leave — Boutique Centrale', $content);
    }

    public function testBuildTranslatesPauseDescriptionInShiftEvent(): void
    {
        $user    = ['id' => 5, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $content = $this->service->build([$this->shift()], [], $this->store(), $user, []);

        $this->assertStringContainsString('DESCRIPTION:Break: 30 min', $content);
    }

    public function testBuildRestoresPreviousLocaleAfterCompletion(): void
    {
        $this->translations->setLocale('fr');

        $user = ['id' => 6, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'ja'];
        $this->service->build([], [$this->timeoff()], $this->store(), $user);

        $this->assertSame('fr', $this->translations->getLocale());
    }

    public function testBuildUsesShiftTypeFallbackWhenTypeUnknown(): void
    {
        $user    = ['id' => 7, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $content = $this->service->build([$this->shift()], [], $this->store(), $user, []);

        $this->assertStringContainsString('SUMMARY:Shift — Boutique Centrale', $content);
    }

    /**
     * Régression : le flux exportait DTSTART/DTEND en heure locale avec un simple
     * `TZID=Europe/Paris` sans bloc VTIMEZONE associé (non conforme RFC 5545
     * §3.6.5). On exporte maintenant directement en UTC, non ambigu pour tout
     * client. 2026-09-21 est en heure d'été (Europe/Paris = UTC+2) : 09:00/17:00
     * locaux deviennent 07:00/15:00 UTC.
     */
    public function testBuildConvertsShiftTimesToUtc(): void
    {
        $user    = ['id' => 8, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $content = $this->service->build([$this->shift()], [], $this->store(), $user, []);

        $this->assertStringContainsString('DTSTART:20260921T070000Z', $content);
        $this->assertStringContainsString('DTEND:20260921T150000Z', $content);
        $this->assertStringNotContainsString('TZID', $content);
    }

    public function testBuildConvertsCrossMidnightShiftEndDateCorrectly(): void
    {
        $user  = ['id' => 9, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $shift = [
            'id'             => 11,
            'shift_date'     => '2026-09-21',
            'start_time'     => '22:00',
            'end_time'       => '06:00',
            'cross_midnight' => 1,
        ];
        $content = $this->service->build([$shift], [], $this->store(), $user, []);

        $this->assertStringContainsString('DTSTART:20260921T200000Z', $content);
        $this->assertStringContainsString('DTEND:20260922T040000Z', $content);
    }

    public function testBuildFallsBackToTheInstanceTimezoneWhenStoreTimezoneIsInvalid(): void
    {
        $user    = ['id' => 10, 'first_name' => 'John', 'last_name' => 'Smith', 'language' => 'en'];
        $store   = $this->store();
        $store['timezone'] = 'Not/ATimezone';
        $content = $this->service->build([$this->shift()], [], $store, $user, []);

        // Avant : repli silencieux en UTC, donc des heures décalées dans l'agenda (+9 h à Tokyo). Maintenant : fuseau de l'instance.
        $expected = (new \DateTimeImmutable('2026-09-21 09:00', new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $this->assertStringContainsString('DTSTART:' . $expected, $content);
        $this->assertStringContainsString('X-WR-TIMEZONE:' . date_default_timezone_get(), $content);
    }
}
