<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Contract\Extension\NotificationType;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Services\NotificationTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Registre unique des types de notification : types du Core, ancien format et format enrichi des bundles,
 * catégories, valeurs par défaut des canaux et type d'objet pour le « lu » automatique.
 */
final class NotificationTypeRegistryTest extends TestCase
{
    /** @param array<string, mixed> $declared types déclarés par un bundle actif */
    private function registry(array $declared): NotificationTypeRegistry
    {
        $bundle = new class extends Bundle {
            public mixed $declared = [];

            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function notificationTypes(): array
            {
                return $this->declared;
            }
        };
        $bundle->declared = $declared;
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['Bacme' => $bundle]);

        return new NotificationTypeRegistry(new ExtensionRegistry($manager));
    }

    public function testCoreTypesCarryCategoryChannelsAndReference(): void
    {
        $assigned = $this->registry([])->get('shift_assigned');

        $this->assertSame('planning', $assigned->category);
        $this->assertSame('shift', $assigned->referenceType);
        $this->assertTrue($assigned->defaultFor('push'));
        $this->assertTrue($assigned->defaultFor('email'), 'une décision de planning part aussi par e-mail');
        $this->assertFalse($this->registry([])->get('shift_updated')->defaultFor('email'));
        $this->assertFalse($assigned->defaultFor('sms'), 'canal inconnu : jamais activé');
    }

    public function testLegacyFormatGetsDefaults(): void
    {
        $type = $this->registry(['acme_ready' => '🧩'])->get('acme_ready');

        $this->assertSame('🧩', $type->icon);
        $this->assertSame('system', $type->category);
        $this->assertTrue($type->defaultFor('push'));
        $this->assertFalse($type->defaultFor('email'));
        $this->assertNull($type->referenceType);
    }

    public function testEnrichedFormatIsRead(): void
    {
        $type = $this->registry(['acme_done' => [
            'icon' => '✓', 'category' => 'requests', 'channels' => ['push' => false, 'email' => true], 'reference_type' => 'acme_job',
        ]])->get('acme_done');

        $this->assertSame('requests', $type->category);
        $this->assertFalse($type->defaultFor('push'));
        $this->assertTrue($type->defaultFor('email'));
        $this->assertSame('acme_job', $type->referenceType);
    }

    public function testCoreTypeCannotBeOverriddenByABundle(): void
    {
        $registry = $this->registry(['shift_assigned' => '💥']);

        $this->assertSame('📅', $registry->get('shift_assigned')->icon);
    }

    /** Une déclaration invalide est ignorée (journalisée), sans empêcher les autres types du même bundle. */
    public function testInvalidDeclarationsAreSkipped(): void
    {
        $registry = $this->registry([
            'acme_ok'      => ['category' => 'messages'],
            'acme_badcat'  => ['category' => 'nope'],
            'acme_badchan' => ['channels' => ['push' => 'yes']],
            'acme_longico' => 'beaucoup trop long',
            'Bad Type'     => '•',
        ]);

        $this->assertNotNull($registry->get('acme_ok'));
        foreach (['acme_badcat', 'acme_badchan', 'acme_longico', 'Bad Type'] as $invalid) {
            $this->assertNull($registry->get($invalid), $invalid);
        }
    }

    public function testByCategoryFollowsDisplayOrderAndSkipsEmptyOnes(): void
    {
        $grouped = $this->registry([
            'acme_msg' => ['category' => 'messages'],
            'acme_req' => ['category' => 'requests'],
        ])->byCategory();

        $this->assertSame(['planning', 'requests', 'messages'], array_keys($grouped));
        $this->assertCount(4, $grouped['planning']);
    }

    /** Les alertes système des Owners existent dans le registre (icône, push) mais ne sont pas une préférence à cocher. */
    public function testOwnerAlertsAreRegisteredButNotInThePreferenceGrid(): void
    {
        $registry = $this->registry([]);

        foreach (array_keys(\kintai\Core\Services\SystemNotificationService::REFERENCE_IDS) as $type) {
            $this->assertNotNull($registry->get($type), $type);
            $this->assertSame('system', $registry->get($type)->category);
            $this->assertSame('system', $registry->get($type)->referenceType);
            $this->assertTrue(NotificationTypeRegistry::isOwnerAlert($type));
        }
        $this->assertArrayNotHasKey('system', $registry->byCategory());
        $this->assertFalse(NotificationTypeRegistry::isOwnerAlert('shift_assigned'));
    }

    public function testFromDeclarationRejectsMalformedValues(): void
    {
        foreach ([42, ['channels' => 'all'], ['reference_type' => 5], ['icon' => 3]] as $bad) {
            try {
                NotificationType::fromDeclaration('acme_x', $bad);
                $this->fail('déclaration invalide acceptée : ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
