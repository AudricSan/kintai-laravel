<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;

/**
 * Outils communs aux tests du bundle : activation, identifiants Google de l'instance, faux Google (`Http::fake`), shifts
 * dans la fenêtre de synchronisation.
 */
trait GoogleCalendarFixtures
{
    use CreatesAccess;
    use InstallsBundles;

    protected Store $store;

    protected function bootGoogleCalendar(): void
    {
        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha']);
        $this->enableBundle('google-calendar');
    }

    /**
     * Identifiants du projet Google saisis par l'Owner et fonction activée.
     */
    protected function configureGoogle(bool $enabled = true): void
    {
        app(GoogleCalendarSettings::class)->save($enabled, 'client-id-123', 'client-secret-xyz');
    }

    protected function employee(): User
    {
        return $this->employeeOf($this->store);
    }

    /**
     * Liaison active dont l'agenda existe déjà chez Google.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function connection(User $user, array $attributes = []): CalendarConnection
    {
        return CalendarConnection::factory()->withCalendar()->create(['user_id' => $user->id, ...$attributes]);
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('kintai.timezone'))->startOfDay();
    }

    /**
     * Shift de 09:00 à 17:00 (heures locales) dans `$days` jours, dans la fenêtre de synchronisation par défaut.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function shiftFor(?User $user, int $days = 3, array $attributes = []): Shift
    {
        $date = $this->today()->addDays($days)->toDateString();

        return Shift::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $user?->id,
            'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'),
            'ends_at' => Shift::localTime($date, '17:00'),
            'pause_minutes' => 30,
            ...$attributes,
        ]);
    }

    /**
     * Faux Google : jeton d'accès, agenda, événements, révocation. `$overrides` remplace la réponse d'un appel, par
     * « MÉTHODE fragment-d'URL » (le premier qui correspond gagne) ; une valeur peut être une réponse ou une liste de
     * réponses consommées une à une (la dernière se répète).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeGoogle(array $overrides = []): void
    {
        $counter = 0;

        // Repart d'un client vierge : sans cela, les faux d'un appel précédent répondraient avant ceux-ci.
        Http::swap(new Factory);

        Http::fake(function (Request $request) use (&$counter, &$overrides) {
            foreach ($overrides as $key => $response) {
                [$method, $fragment] = explode(' ', $key, 2);

                if ($request->method() === $method && str_contains($request->url(), $fragment)) {
                    if (is_array($response) && array_is_list($response)) {
                        $next = count($response) > 1 ? array_shift($response) : $response[0];
                        $overrides[$key] = $response;

                        return $next;
                    }

                    return $response;
                }
            }

            $url = $request->url();
            $counter++;

            return match (true) {
                str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'access-token-1', 'refresh_token' => 'refresh-token-1']),
                str_contains($url, 'oauth2.googleapis.com/revoke') => Http::response([], 200),
                $request->method() === 'POST' && str_ends_with($url, '/calendars') => Http::response(['id' => 'cal-new']),
                $request->method() === 'POST' && str_ends_with($url, '/events') => Http::response(['id' => 'evt-'.$counter]),
                $request->method() === 'DELETE' => Http::response('', 204),
                default => Http::response([]),
            };
        });
    }

    /**
     * Google injoignable : toute requête échoue comme une coupure réseau.
     */
    protected function fakeNetworkDown(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Network is down'));
    }

    /**
     * Requêtes envoyées à Google dont l'URL se termine par `$suffix` (`/calendars` sans les `/calendars/x/events`).
     *
     * @return Collection<int, Request>
     */
    protected function sentEnding(string $method, string $suffix): Collection
    {
        return $this->sent($method, $suffix)->filter(fn (Request $request): bool => str_ends_with($request->url(), $suffix))->values();
    }

    /**
     * Requêtes envoyées à Google, filtrées par méthode et fragment d'URL.
     *
     * @return Collection<int, Request>
     */
    protected function sent(string $method, string $fragment): Collection
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === $method && str_contains($request->url(), $fragment))
            ->values();
    }
}
