<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserDashboardPref;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Préférences de tableau de bord pour les tests des contrôleurs : disposition et widgets préparés dans la table
 * user_dashboard_prefs avant l'action, écritures vérifiées à la fin du test (une valeur `$this->anything()` n'est
 * pas comparée). Remplace les doubles de l'ancien dépôt.
 */
trait PrefsExpectations
{
    /** @var list<array{int, string, mixed}> */
    private array $expectedLayouts = [];
    /** @var list<array{int, array, string}> */
    private array $expectedWidgets = [];
    private bool $expectNoPrefsWrite = false;
    /** @var array<string, array|null> disposition initiale par utilisateur et type */
    private array $seededLayouts = [];

    #[\PHPUnit\Framework\Attributes\Before]
    protected function prefsDatabase(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        foreach ([1, 7, 9, 20, 99] as $userId) {
            kintai_test_user($userId);
        }
    }

    private function seedLayout(int $userId, string $type, ?array $layout): void
    {
        $this->seededLayouts["$userId:$type"] = $layout;
        if ($layout !== null) {
            UserDashboardPref::saveLayout($userId, $type, $layout);
        }
    }

    private function seedWidgets(int $userId, string $type, ?array $widgets): void
    {
        if ($widgets !== null) {
            kintai_test_user($userId);
            UserDashboardPref::saveWidgets($userId, $widgets, $type);
        }
    }

    private function expectLayout(int $userId, string $type, mixed $layout): void
    {
        $this->expectedLayouts[] = [$userId, $type, $layout];
    }

    private function expectWidgets(int $userId, array $widgets, string $type): void
    {
        $this->expectedWidgets[] = [$userId, $widgets, $type];
    }

    /** @var list<array{int, array}> */
    private array $expectedNavHidden = [];

    private function expectNavHidden(int $userId, array $hidden): void
    {
        $this->expectedNavHidden[] = [$userId, $hidden];
    }

    private function expectNoPrefsWrite(): void
    {
        $this->expectNoPrefsWrite = true;
    }

    protected function assertPostConditions(): void
    {
        foreach ($this->expectedLayouts as [$userId, $type, $layout]) {
            if (!$layout instanceof Constraint) {
                $this->assertSame($layout, UserDashboardPref::getLayout($userId, $type), "disposition enregistrée ($userId, $type)");
            }
        }
        foreach ($this->expectedWidgets as [$userId, $widgets, $type]) {
            $this->assertSame($widgets, UserDashboardPref::getEnabledWidgets($userId, $type), "widgets enregistrés ($userId, $type)");
        }
        foreach ($this->expectedNavHidden as [$userId, $hidden]) {
            $this->assertSame($hidden, \App\Models\UserNavPref::getHiddenRow($userId), "éléments de navigation masqués ($userId)");
        }
        if ($this->expectNoPrefsWrite) {
            foreach ($this->seededLayouts as $key => $layout) {
                [$userId, $type] = explode(':', $key);
                $this->assertSame($layout, UserDashboardPref::getLayout((int) $userId, $type), 'la disposition ne devait pas changer');
            }
        }
    }
}
