<?php

namespace Tests\Feature\System;

use App\Activity\ActivityActionRegistry;
use App\Models\Role;
use App\Models\User;
use App\Navigation\NavigationRegistry;
use App\Notifications\NotificationTypeRegistry;
use App\System\CoreSystemAlerts;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les libellés passés par variable (types de notification, textes d'alerte, menu, actions du journal) échappent au test des
 * chaînes littérales : ils ont leur propre garde-fou.
 */
class SystemTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function french(): array
    {
        return json_decode((string) file_get_contents(lang_path('fr.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_system_notification_types_and_alert_texts_are_translated(): void
    {
        $french = $this->french();
        $registry = app(NotificationTypeRegistry::class);

        foreach (array_keys(CoreSystemAlerts::BODIES) as $type) {
            $this->assertArrayHasKey($registry->get($type)->label ?? '', $french, "Missing translation of the label of {$type}");
            $this->assertArrayHasKey(CoreSystemAlerts::BODIES[$type], $french, "Missing translation of the text of {$type}");
        }

        foreach (CoreSystemAlerts::CHANNEL_PROBLEMS as $label) {
            $this->assertArrayHasKey($label, $french);
        }
    }

    public function test_menu_entries_and_activity_actions_are_translated(): void
    {
        $french = $this->french();
        $this->seed(RoleSeeder::class);
        $owner = User::factory()->create();
        $owner->assignRole(Role::where('slug', 'owner')->firstOrFail());
        $items = collect(app(NavigationRegistry::class)->visibleTo($owner))->keyBy('key');

        foreach (['docs', 'scheduled-tasks', 'report-issue'] as $key) {
            $this->assertTrue($items->has($key), "Menu entry {$key} is missing");
            $this->assertArrayHasKey($items[$key]->label, $french);
        }

        $actions = app(ActivityActionRegistry::class);

        foreach (['cron.token_created', 'cron.token_revoked', 'docs.synced', 'support.issue_reported'] as $action) {
            $this->assertTrue($actions->has($action));
            $this->assertArrayHasKey($actions->all()[$action]['label'], $french);
        }
    }
}
