<?php

namespace Tests\Feature\Docs;

use App\Docs\DocsRenderer;
use App\Docs\DocsStore;
use App\Docs\DocsSync;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const SIDEBAR = "**Getting Started**\n\n- [Home](Home)\n- [Installation](/AudricSan/Kintai/wiki/Installation)\n\n**Reference**\n\n- [Missing page](Missing)\n- [Online](https://example.com/x)\n";

    private string $directory;

    /** @var array<string, mixed> Contenu courant du faux wiki, par nom de page. */
    private array $wiki = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-docs-'.bin2hex(random_bytes(4));
        config(['kintai.docs.path' => $this->directory, 'kintai.docs.repository' => 'Acme/Kintai']);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->directory));
        $this->seedRoles();
    }

    private function store(): DocsStore
    {
        return $this->app->make(DocsStore::class);
    }

    /**
     * @param  array<string, mixed>  $overrides  Réponses par nom de page (corps, ou réponse `Http::response()`).
     */
    private function fakeWiki(array $overrides = []): void
    {
        $this->wiki = $overrides + [
            '_Sidebar' => self::SIDEBAR,
            'Home' => "# Home\n\nWelcome. See [Install](Installation).",
            'Installation' => "# Installation\n\nSteps.",
            'Missing' => Http::response('Not Found', 404),
        ];

        // Enregistré une seule fois (le premier faux qui correspond répond) ; les appels suivants changent seulement le contenu.
        if ($this->faked) {
            return;
        }

        $this->faked = true;

        Http::fake(function (Request $request) {
            if (preg_match('#^https://raw\.githubusercontent\.com/wiki/Acme/Kintai/(.+)\.md$#', $request->url(), $m) !== 1) {
                return Http::response('unexpected', 500);
            }

            $page = $this->wiki[rawurldecode($m[1])] ?? Http::response('Not Found', 404);

            return is_string($page) ? Http::response($page) : $page;
        });
    }

    // Synchronisation

    public function test_the_sync_downloads_the_sidebar_and_the_listed_pages(): void
    {
        $this->fakeWiki();

        $this->artisan('kintai:docs:sync')->assertSuccessful();

        $this->assertTrue($this->store()->isSynced());
        $this->assertStringContainsString('Welcome', (string) $this->store()->read('Home'));
        $this->assertNotNull($this->store()->read('Installation'));
        $this->assertNull($this->store()->read('Missing'));
        $this->assertNotNull($this->app->make(AppSettings::class)->docsLastSyncAt());
        // Le lien externe du sommaire n'est pas une page.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'example.com'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'Kintai-DocsSync/1.0'));
    }

    public function test_the_sync_writes_only_what_changed(): void
    {
        $this->fakeWiki();
        $first = $this->app->make(DocsSync::class)->sync();
        $this->assertSame(3, $first['added']);
        $this->assertSame(1, $first['skipped']);

        $mtime = filemtime($this->directory.'/Home.md');
        clearstatcache();
        sleep(1);

        $this->fakeWiki(['Installation' => "# Installation\n\nNew steps."]);
        $second = $this->app->make(DocsSync::class)->sync();

        $this->assertSame(['added' => 0, 'updated' => 1, 'unchanged' => 2], ['added' => $second['added'], 'updated' => $second['updated'], 'unchanged' => $second['unchanged']]);
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->directory.'/Home.md'));
        $this->assertStringContainsString('New steps', (string) $this->store()->read('Installation'));
    }

    public function test_pages_that_left_the_sidebar_are_removed(): void
    {
        $this->fakeWiki();
        $this->app->make(DocsSync::class)->sync();

        $this->fakeWiki(['_Sidebar' => "- [Home](Home)\n"]);
        $result = $this->app->make(DocsSync::class)->sync();

        $this->assertSame(1, $result['removed']);
        $this->assertNull($this->store()->read('Installation'));
        $this->assertNotNull($this->store()->read('Home'));
    }

    public function test_a_failing_sidebar_changes_nothing(): void
    {
        $this->fakeWiki(['_Sidebar' => Http::response('boom', 500)]);

        $this->artisan('kintai:docs:sync')->assertFailed();

        $this->assertFalse($this->store()->isSynced());
        $this->assertNull($this->app->make(AppSettings::class)->docsLastSyncAt());
    }

    public function test_an_unreadable_page_is_an_error_and_keeps_the_old_content(): void
    {
        $this->fakeWiki();
        $this->app->make(DocsSync::class)->sync();

        $this->fakeWiki(['Home' => Http::response('boom', 500)]);
        $result = $this->app->make(DocsSync::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Home: HTTP 500', $result['errors'][0]);
        $this->assertStringContainsString('Welcome', (string) $this->store()->read('Home'));
    }

    public function test_integrity_checks_refuse_oversized_binary_and_badly_encoded_pages(): void
    {
        $this->fakeWiki([
            'Home' => str_repeat('a', DocsStore::PAGE_MAX_BYTES + 1),
            'Installation' => "text\0binary",
            'Missing' => "caf\xE9 latin1",
        ]);
        // « Missing » est maintenant listée et lisible : on vérifie l'encodage.
        $result = $this->app->make(DocsSync::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertCount(3, $result['errors']);
        $this->assertNull($this->store()->read('Home'));
        $this->assertNull($this->store()->read('Installation'));
        $this->assertNull($this->store()->read('Missing'));
    }

    public function test_an_invalid_repository_is_refused_without_any_request(): void
    {
        config(['kintai.docs.repository' => '../../etc']);
        Http::fake();

        $this->assertFalse($this->app->make(DocsSync::class)->sync()['ok']);
        Http::assertNothingSent();
    }

    public function test_the_sync_is_scheduled_daily(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'kintai:docs:sync'));

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
    }

    // Noms de pages

    public function test_page_names_are_validated(): void
    {
        foreach (['Home', 'Guide-employé', 'ホーム', 'a_b-1'] as $slug) {
            $this->assertTrue(DocsStore::isValidSlug($slug), $slug);
        }
        foreach (['', '..', '../x', 'a/b', 'a\\b', '_Sidebar', 'a.md', "a\0b", str_repeat('a', 151)] as $slug) {
            $this->assertFalse(DocsStore::isValidSlug($slug), $slug);
        }

        $this->assertNull($this->store()->read('../.env'));
        $this->assertSame('Page', DocsStore::slugFromTarget('/Acme/Kintai/wiki/Page#anchor'));
        $this->assertSame('Page', DocsStore::slugFromTarget('Page.md'));
        $this->assertNull(DocsStore::slugFromTarget('https://example.com/Page'));
        $this->assertNull(DocsStore::slugFromTarget('javascript:alert(1)'));
        $this->assertNull(DocsStore::slugFromTarget('../../secret.txt'));
    }

    // Rendu

    public function test_the_rendering_is_safe(): void
    {
        $html = $this->app->make(DocsRenderer::class)->render(
            "<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))\n\n[data](data:text/html;base64,AAAA)\n\n**bold**",
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_internal_links_are_rewritten_and_external_ones_are_marked(): void
    {
        $html = $this->app->make(DocsRenderer::class)->render(
            '[a](Installation) [b](/Acme/Kintai/wiki/Setup%20Guide#top) [c](Page.md) [d](#local) [e](https://example.com) [f](../../etc/passwd) [g](mailto:a@b.test)',
        );

        $this->assertStringContainsString('href="'.route('docs.show', ['page' => 'Installation']).'"', $html);
        $this->assertStringContainsString('href="'.url('/docs/Page').'"', $html);
        $this->assertStringContainsString('href="#local"', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('<a rel="nofollow noopener noreferrer" target="_blank" class="external-link" href="https://example.com">', $html);
        // Les liens internes restent dans l'application.
        $this->assertStringNotContainsString('target="_blank" class="external-link" href="'.url('/docs'), $html);
        // Seul le nom de la page est gardé : jamais un chemin.
        $this->assertStringNotContainsString('etc/passwd', $html);
        $this->assertStringNotContainsString('..', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
    }

    public function test_relative_images_are_replaced_by_their_alt_text_and_the_language_line_is_dropped(): void
    {
        $html = $this->app->make(DocsRenderer::class)->render("🌐 **English** · [Français](Accueil)\n\n![diagram](images/a.png) ![remote](https://example.com/a.png)");

        $this->assertStringNotContainsString('Accueil', $html);
        $this->assertStringNotContainsString('images/a.png', $html);
        $this->assertStringContainsString('diagram', $html);
        $this->assertStringContainsString('<img src="https://example.com/a.png"', $html);
    }

    // Page

    public function test_the_unavailable_page_is_shown_until_a_first_sync(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('docs.show'))->assertOk()->assertSeeHtml('data-test="docs-unavailable"')->assertDontSeeHtml('data-test="docs-sync"');
        $this->get(route('docs.show', ['page' => 'Home']))->assertOk()->assertSeeHtml('data-test="docs-unavailable"');
    }

    public function test_the_docs_require_a_signed_in_user(): void
    {
        $this->get(route('docs.show'))->assertRedirect(route('login'));
    }

    public function test_the_index_redirects_to_the_first_page_and_pages_render_with_a_toc(): void
    {
        $this->fakeWiki();
        $this->app->make(DocsSync::class)->sync();
        $this->actingAs(User::factory()->create());

        $this->get(route('docs.show'))->assertRedirect(route('docs.show', ['page' => 'Home']));

        $this->get(route('docs.show', ['page' => 'Home']))
            ->assertOk()
            ->assertSee('Welcome.')
            ->assertSee('Getting Started')
            ->assertSee(route('docs.show', ['page' => 'Installation']), false)
            ->assertSeeHtml('data-test="docs-toc"');
    }

    public function test_an_unknown_or_malformed_page_is_a_404(): void
    {
        $this->fakeWiki();
        $this->app->make(DocsSync::class)->sync();
        $this->actingAs(User::factory()->create());

        $this->get(route('docs.show', ['page' => 'Nope']))->assertNotFound();
        $this->get('/docs/..%2F..%2F.env')->assertNotFound();
        $this->get('/docs/'.rawurlencode('_Sidebar'))->assertNotFound();
        $this->get('/docs/a.md')->assertNotFound();
    }

    public function test_the_menu_entry_is_open_to_everyone(): void
    {
        $this->actingAs($this->employeeOf(Store::factory()->create()))->get(route('dashboard'))->assertSee(route('docs.show'));
    }

    // Bouton « Synchroniser »

    public function test_only_the_owner_can_synchronize_from_the_page(): void
    {
        $this->fakeWiki();
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->get(route('docs.show'))->assertSeeHtml('data-test="docs-sync"');
        Livewire::test('pages::docs.show')->call('sync');

        $this->assertTrue($this->store()->isSynced());
        $entry = ActivityEntry::query()->where('action', 'docs.synced')->firstOrFail();
        $this->assertTrue($entry->properties['success']);

        File::deleteDirectory($this->directory);
        $this->actingAs($this->employeeOf(Store::factory()->create()));
        Livewire::test('pages::docs.show')->call('sync')->assertForbidden();
        $this->assertFalse($this->store()->isSynced());
    }
}
