<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Services\TranslationService;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * notification_body() : le texte d'une notification suit la langue courante du lecteur (body_key + params), et
 * retombe sur l'instantané body pour les lignes antérieures à body_key ou une clé devenue introuvable.
 */
final class NotificationBodyHelperTest extends TestCase
{
    private ?TranslationService $previous = null;
    private TranslationService $translations;

    protected function setUp(): void
    {
        $container = Container::getInstance();
        if ($container->has(TranslationService::class)) {
            $this->previous = $container->make(TranslationService::class);
        }
        $this->translations = new TranslationService(
            new JsonTranslationRepository(BASE_PATH . '/lang', BASE_PATH . '/app/Bundles'),
            new JsonLanguageRepository(BASE_PATH . '/lang'),
        );
        $container->instance(TranslationService::class, $this->translations);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            Container::getInstance()->instance(TranslationService::class, $this->previous);
            return;
        }
        // Aucun service auparavant : le retirer, sinon __() traduirait dans les tests suivants qui attendent la clé.
        $instancesProp = new \ReflectionProperty(Container::class, 'instances');
        $instances     = $instancesProp->getValue(Container::getInstance());
        unset($instances[TranslationService::class]);
        $instancesProp->setValue(Container::getInstance(), $instances);
    }

    private function lang(string $locale): array
    {
        return json_decode((string) file_get_contents(BASE_PATH . "/lang/{$locale}.json"), true);
    }

    public function testTranslatesBodyKeyWithParamsInTheCurrentLocale(): void
    {
        $n = ['body_key' => 'notif_shift_assigned_body', 'params' => ['date' => '2026-09-20', 'start' => '09:00', 'end' => '17:00', 'store' => 'S'], 'body' => 'snapshot'];

        $this->translations->setLocale('en');
        $en = notification_body($n);
        $this->translations->setLocale('ja');
        $ja = notification_body($n);

        $this->assertSame(str_replace([':date', ':start', ':end', ':store'], ['2026-09-20', '09:00', '17:00', 'S'], $this->lang('en')['notif_shift_assigned_body']), $en);
        $this->assertSame(str_replace([':date', ':start', ':end', ':store'], ['2026-09-20', '09:00', '17:00', 'S'], $this->lang('ja')['notif_shift_assigned_body']), $ja);
    }

    public function testFallsBackToSnapshotWhenKeyIsUnknown(): void
    {
        $this->assertSame('Texte figé', notification_body(['body_key' => 'notif_disabled_bundle_body', 'params' => [], 'body' => 'Texte figé']));
    }

    public function testFallsBackToSnapshotForLegacyRowsWithoutKey(): void
    {
        $this->assertSame('Ancien texte', notification_body(['body_key' => null, 'body' => 'Ancien texte']));
        $this->assertSame('', notification_body([]));
    }

    public function testBadgeLabelCapsAt99Plus(): void
    {
        $this->assertSame('0', notification_badge_label(0));
        $this->assertSame('99', notification_badge_label(99));
        $this->assertSame('99+', notification_badge_label(100));
    }
}
