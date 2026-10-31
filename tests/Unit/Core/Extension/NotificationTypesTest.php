<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Extension;

use App\Bundles\Bundle;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Extension\ExtensionRegistry;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Types de notification et libellés par convention (étape 2i du contrat de bundle) : Bundle::notificationTypes()
 * (contrat 1.7), libellés « notif_{type} » et « audit_{action} » trouvés dans les traductions des bundles.
 */
final class NotificationTypesTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        kintai_test_translator(['en' => [
            'notif_acme_ready'        => 'Acme est prêt',
            'notif_acme_legacy'       => 'Ancienne notification Acme',
            'audit_acme_board_pinned' => 'Tableau épinglé',
            'notif_shift_assigned'    => 'Shift assigné',
        ]], 'en');
    }

    protected function tearDown(): void
    {
        kintai_test_translator_reset();
    }

    /** @param array<string, mixed> $active slug => types déclarés (array, ou \Throwable levée) */
    private function activate(array $active): void
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function notificationTypes(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    public function testDeclaredTypesJoinTheCatalogWithoutOverridingCoreTypes(): void
    {
        $this->activate([
            'acme'  => ['acme_ready' => '🧩', 'shift_assigned' => '💥', 'Bad Type' => 'x', 'acme_long_icon' => 'beaucoup trop long'],
            'other' => ['acme_ready' => '❌'],
            'boom'  => new \RuntimeException('kaput'),
        ]);

        $catalog = notification_type_catalog();

        $this->assertSame(['label' => 'Acme est prêt', 'icon' => '🧩'], $catalog['acme_ready']);
        $this->assertSame('📅', $catalog['shift_assigned']['icon'], 'un type du Core ne peut pas être redéfini');
        $this->assertArrayNotHasKey('acme_long_icon', $catalog);
        $this->assertSame('🧩', notification_type_icon('acme_ready'));
    }

    /** Bundle désactivé : ses notifications déjà reçues gardent un libellé traduit si sa traduction existe. */
    public function testLabelFallsBackToTheTranslationConvention(): void
    {
        $this->activate([]);

        $this->assertSame('Ancienne notification Acme', notification_type_label('acme_legacy'));
        $this->assertSame('unknown_type', notification_type_label('unknown_type'));
        $this->assertSame('•', notification_type_icon('acme_legacy'));
    }

    public function testAuditActionLabel(): void
    {
        $this->assertSame('Connu', audit_action_label('auth.login', ['auth.login' => 'Connu']));
        $this->assertSame('Tableau épinglé', audit_action_label('acme_board.pinned'));
        $this->assertSame('acme.unknown', audit_action_label('acme.unknown'));
    }
}
