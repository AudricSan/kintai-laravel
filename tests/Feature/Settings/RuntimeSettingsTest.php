<?php

namespace Tests\Feature\Settings;

use App\Models\Store;
use App\Settings\AppSettings;
use App\Settings\PublicUrl;
use App\Settings\RuntimeSettings;
use App\Settings\ThemeColorPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class RuntimeSettingsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    private function apply(): void
    {
        $this->app->make(RuntimeSettings::class)->apply();
    }

    public function test_the_stored_language_and_time_zone_replace_the_configuration_at_startup(): void
    {
        $this->settings()->setMany(['instance.locale' => 'ja', 'instance.timezone' => 'Europe/Paris']);

        $this->apply();

        $this->assertSame('ja', config('app.locale'));
        $this->assertSame('ja', app()->getLocale());
        $this->assertSame('Europe/Paris', config('kintai.timezone'));
    }

    public function test_an_unsupported_stored_language_or_time_zone_is_ignored(): void
    {
        $this->settings()->setMany(['instance.locale' => 'xx', 'instance.timezone' => 'Nowhere/Land']);

        $this->apply();

        $this->assertSame('en', config('app.locale'));
        $this->assertSame('UTC', config('kintai.timezone'));
    }

    public function test_nothing_stored_changes_nothing(): void
    {
        $locale = config('app.locale');
        $timezone = config('kintai.timezone');

        $this->apply();

        $this->assertSame($locale, config('app.locale'));
        $this->assertSame($timezone, config('kintai.timezone'));
    }

    public function test_the_instance_language_is_used_for_users_without_their_own(): void
    {
        $this->seedRoles();
        $this->settings()->set('instance.locale', 'fr');
        $this->apply();

        $user = $this->owner();
        $user->forceFill(['locale' => null])->save();

        $this->actingAs($user)->get(route('settings.app'))->assertSee("Réglages de l'instance");
    }

    public function test_links_generated_in_console_use_the_public_url(): void
    {
        $this->settings()->set('organization.public_url', 'https://planning.example.com/kintai');
        $this->apply();

        $this->assertSame('https://planning.example.com/kintai/dashboard', route('dashboard'));
        $this->assertStringStartsWith('https://planning.example.com/kintai/', url('/anything'));

        $store = Store::factory()->create();
        $this->assertStringStartsWith('https://planning.example.com/kintai/ical/', route('ical.feed', ['token' => 'abc', 'store' => $store->id]));

        $this->settings()->set('organization.public_url', '');
        $this->apply();

        $this->assertSame(rtrim((string) config('app.url'), '/').'/dashboard', route('dashboard'));
        URL::forceRootUrl(null);
        URL::forceScheme(null);
    }

    public function test_the_public_url_is_normalized(): void
    {
        $this->assertSame('https://example.com', PublicUrl::normalize('HTTPS://Example.COM/'));
        $this->assertSame('http://localhost:8000/app', PublicUrl::normalize(' http://localhost:8000/app/ '));
        $this->assertSame('https://[::1]:8443', PublicUrl::normalize('https://[::1]:8443'));

        foreach ([null, '', 'example.com', 'ftp://example.com', 'https://u:p@example.com', 'https://example.com?x=1', 'https://example.com#a', 'https://exa mple.com', str_repeat('a', 300)] as $bad) {
            $this->assertNull(PublicUrl::normalize($bad), (string) $bad);
        }
    }

    public function test_the_public_url_saved_from_the_screen_is_applied_at_once(): void
    {
        $this->seedRoles();

        Livewire::actingAs($this->owner())->test('pages::admin.settings')
            ->set('public_url', 'https://planning.example.com')
            ->call('saveOrganization');

        $this->assertSame('https://planning.example.com/dashboard', route('dashboard'));
    }

    public function test_the_theme_palette_emits_only_customized_groups(): void
    {
        $defaults = ThemeColorPalette::DEFAULTS;

        $this->assertSame('', ThemeColorPalette::toCss($defaults, [], 'auto'));
        $this->assertSame('', ThemeColorPalette::toCss($defaults, ['primary' => '#123456'], 'auto'));

        $css = ThemeColorPalette::toCss(['primary' => '#112233'] + $defaults, [], 'auto');
        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('--kintai-primary:#112233;', $css);
        $this->assertStringContainsString('--color-accent:#112233;', $css);
        $this->assertStringContainsString('--color-accent-foreground:#ffffff;', $css);
        $this->assertStringContainsString('.dark{', $css);
        $this->assertStringNotContainsString('--kintai-danger', $css);

        $manual = ThemeColorPalette::toCss($defaults, ['danger' => '#abcdef'], 'manual');
        $this->assertStringContainsString('.dark{--kintai-danger:#abcdef;', $manual);
    }

    public function test_the_theme_palette_never_emits_an_invalid_color(): void
    {
        $css = ThemeColorPalette::toCss(['primary' => 'red;}body{display:none'] + ThemeColorPalette::DEFAULTS, ['info' => '</style>'], 'manual');

        $this->assertSame('', $css);
    }
}
