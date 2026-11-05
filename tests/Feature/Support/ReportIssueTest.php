<?php

namespace Tests\Feature\Support;

use App\Actions\Support\ReportIssue;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ReportIssueTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const API = 'https://api.github.com/repos/Acme/Kintai/issues';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.support.repository' => 'Acme/Kintai', 'kintai.support.github_token' => null]);
        $this->seedRoles();
        $this->user = $this->employeeOf(Store::factory()->create(), ['name' => 'Hanako Yamada', 'email' => 'hanako@example.test']);
        $this->actingAs($this->user);
    }

    private function report(string $title = 'Calendar is empty', string $description = 'Nothing shows on Monday.')
    {
        return Livewire::test('pages::support.report-issue')->set('title', $title)->set('description', $description);
    }

    public function test_the_page_is_open_to_any_signed_in_user_and_listed_in_the_menu(): void
    {
        $this->get(route('support.report-issue'))->assertOk()->assertSee('Report a problem');
        $this->get(route('dashboard'))->assertSee(route('support.report-issue'));

        auth()->logout();
        $this->get(route('support.report-issue'))->assertRedirect(route('login'));
    }

    public function test_with_a_token_the_issue_is_created_through_the_api(): void
    {
        config(['kintai.support.github_token' => 'ghp_secret']);
        Http::fake([self::API => Http::response(['html_url' => 'https://github.com/Acme/Kintai/issues/42'], 201)]);

        $this->report()->call('submit')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSeeHtml('data-test="issue-created"')
            ->assertSee('https://github.com/Acme/Kintai/issues/42')
            ->assertSet('title', '')
            ->assertSet('description', '');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::API
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer ghp_secret')
                && $request->hasHeader('Accept', 'application/vnd.github+json')
                && $request->hasHeader('User-Agent', 'Kintai-IssueReport/1.0')
                && $request['title'] === 'Calendar is empty'
                && str_starts_with($request['body'], 'Nothing shows on Monday.');
        });
    }

    public function test_the_body_carries_no_personal_data_unless_asked(): void
    {
        config(['kintai.support.github_token' => 'ghp_secret']);
        Http::fake([self::API => Http::response(['html_url' => 'https://github.com/Acme/Kintai/issues/1'], 201)]);

        $this->report()->set('includeTechnical', false)->call('submit');
        $this->report()->set('includeTechnical', true)->call('submit');
        $this->report()->set('includeTechnical', true)->set('includeIdentity', true)->call('submit');

        $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]['body'])->all();
        $this->assertCount(3, $bodies);

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('hanako@example.test', $body);
        }

        $this->assertStringNotContainsString('Hanako', $bodies[0]);
        $this->assertStringNotContainsString('Kintai version', $bodies[0]);
        $this->assertStringContainsString('Kintai version: '.config('kintai.version'), $bodies[1]);
        $this->assertStringContainsString('Browser:', $bodies[1]);
        $this->assertStringNotContainsString('Hanako', $bodies[1]);
        $this->assertStringContainsString('Reported by: Hanako Yamada', $bodies[2]);
    }

    public function test_without_a_token_the_user_is_sent_to_the_prefilled_github_form(): void
    {
        Http::fake();

        $component = $this->report('Bug & more', "Line one\nLine two é")->call('submit')->assertHasNoErrors();

        $url = $this->redirectUrl($component);
        $this->assertStringStartsWith('https://github.com/Acme/Kintai/issues/new?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('Bug & more', $query['title']);
        $this->assertStringStartsWith("Line one\nLine two é", $query['body']);
        $this->assertStringNotContainsString('Hanako', $url);
        Http::assertNothingSent();
    }

    public function test_a_failed_api_call_falls_back_to_the_prefilled_form(): void
    {
        config(['kintai.support.github_token' => 'ghp_secret']);

        foreach ([Http::response(['message' => 'Bad credentials'], 401), Http::response(['html_url' => 'https://evil.example/x'], 201), Http::response('not json', 201)] as $response) {
            Http::fake([self::API => $response]);
            RateLimiter::clear('support-report:'.$this->user->id);

            $url = $this->redirectUrl($this->report()->call('submit'));

            $this->assertStringStartsWith('https://github.com/Acme/Kintai/issues/new?', $url);
        }
    }

    public function test_a_connection_error_falls_back_to_the_prefilled_form(): void
    {
        config(['kintai.support.github_token' => 'ghp_secret']);
        Http::fake([self::API => fn () => throw new ConnectionException('timeout')]);

        $this->assertStringStartsWith('https://github.com/Acme/Kintai/issues/new?', $this->redirectUrl($this->report()->call('submit')));
    }

    public function test_the_prefilled_url_is_truncated_to_a_reasonable_length(): void
    {
        $url = $this->redirectUrl($this->report('é', str_repeat('é', ReportIssue::DESCRIPTION_MAX))->call('submit'));

        $this->assertLessThanOrEqual(7000, strlen($url));
        $this->assertStringContainsString('title=%C3%A9', $url);
    }

    public function test_lengths_are_limited(): void
    {
        $this->report(str_repeat('t', ReportIssue::TITLE_MAX + 1), 'ok')->call('submit')->assertHasErrors('title');
        $this->report('ok', str_repeat('d', ReportIssue::DESCRIPTION_MAX + 1))->call('submit')->assertHasErrors('description');
        $this->report('', 'ok')->call('submit')->assertHasErrors('title');
        $this->report('ok', '   ')->call('submit')->assertHasErrors('description');

        Http::fake();
        $this->report(str_repeat('t', ReportIssue::TITLE_MAX), str_repeat('d', ReportIssue::DESCRIPTION_MAX))->call('submit')->assertHasNoErrors();
    }

    public function test_the_rate_limit_is_five_per_hour_and_per_user(): void
    {
        Http::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->report()->call('submit')->assertHasNoErrors();
        }

        $this->report()->call('submit')->assertHasErrors('report')->assertNoRedirect();

        // Un autre utilisateur n'est pas concerné.
        $this->actingAs($this->employeeOf(Store::factory()->create()));
        $this->report()->call('submit')->assertHasNoErrors();
    }

    public function test_invalid_submissions_do_not_use_the_quota(): void
    {
        Http::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->report('', '')->call('submit')->assertHasErrors('title');
        }

        $this->report()->call('submit')->assertHasNoErrors();
    }

    public function test_an_invalid_repository_blocks_the_report(): void
    {
        config(['kintai.support.repository' => 'not a repo']);

        $this->report()->call('submit')->assertHasErrors('report')->assertNoRedirect();
    }

    public function test_the_report_is_logged_without_its_content(): void
    {
        Http::fake();

        $this->report('Secret title', 'Secret description')->call('submit');

        $entry = ActivityEntry::query()->where('action', 'support.issue_reported')->firstOrFail();
        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertFalse($entry->properties['issue_created']);
        $this->assertStringNotContainsString('Secret', json_encode($entry->properties));
    }

    private function redirectUrl($component): string
    {
        $redirect = $component->effects['redirect'] ?? null;
        $this->assertIsString($redirect, 'A redirection was expected.');

        return $redirect;
    }
}
