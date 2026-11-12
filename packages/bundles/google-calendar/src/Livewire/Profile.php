<?php

namespace Kintai\Bundles\GoogleCalendar\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Kintai\Bundles\GoogleCalendar\CalendarSync;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarClient;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\GoogleRedirectUri;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Bundles\GoogleCalendar\OAuthState;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Google Calendar » (zone des réglages personnels) : chacun lie, nomme, resynchronise et délie son propre agenda.
 * Aucun identifiant d'utilisateur ne vient du navigateur : tout part de l'utilisateur connecté. Rien n'est proposé tant que
 * l'Owner n'a pas activé la fonction, sauf à qui est déjà lié.
 */
#[Title('Google Calendar')]
class Profile extends Component
{
    use ResolvesViews;

    public string $nameMode = CalendarConnection::NAME_INSTANCE;

    public string $storeId = '';

    public bool $deleteEvents = false;

    public function mount(): void
    {
        abort_unless($this->usable(), 404);

        $connection = $this->connection();

        if ($connection !== null) {
            $this->nameMode = $connection->name_mode;
            $this->storeId = $connection->store_id === null ? '' : (string) $connection->store_id;
        } else {
            $this->storeId = (string) (array_key_first($this->stores()) ?? '');
        }
    }

    /**
     * Envoie la personne chez Google pour autoriser Kintai (code d'autorisation + PKCE).
     */
    public function connect(GoogleCalendarSettings $settings, GoogleCalendarClient $client, GoogleRedirectUri $redirect): void
    {
        abort_unless($this->usable(), 404);

        if (! $settings->isAvailable()) {
            Flux::toast(variant: 'warning', text: __('Google Calendar linking is not enabled on this instance.'));

            return;
        }

        [$mode, $storeId] = $this->validatedName();
        $oauth = OAuthState::start($mode, $storeId);

        $this->redirect($client->authorizationUrl(
            $redirect->resolve(),
            $oauth['state'],
            GoogleCalendarClient::codeChallenge($oauth['verifier']),
            $this->user()->email,
        ));
    }

    public function saveOptions(CalendarSync $sync): void
    {
        $connection = $this->activeConnection();
        [$mode, $storeId] = $this->validatedName();

        $sync->rename($connection, $mode, $storeId);

        Flux::toast(variant: 'success', text: __('Calendar name saved; it will be updated at the next sync.'));
    }

    /**
     * Remet tout en file (utile après une panne).
     */
    public function resync(CalendarSync $sync): void
    {
        $sync->reconcile($this->activeConnection());

        Flux::toast(variant: 'success', text: __('Resync scheduled.'));
    }

    public function disconnect(CalendarSync $sync, ActivityRecorder $activity): void
    {
        $connection = $this->connection() ?? abort(404);
        $id = $connection->id;

        $sync->disconnect($connection, $this->deleteEvents);
        $activity->recordFor('google_calendar.disconnected', $connection->getMorphClass(), $id, null, ['events_deleted' => $this->deleteEvents], $this->user());

        $this->deleteEvents = false;
        Flux::toast(variant: 'success', text: __('Google Calendar disconnected.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('profile'), [
            'available' => app(GoogleCalendarSettings::class)->isAvailable(),
            'connection' => $this->connection(),
            'stores' => $this->stores(),
            'flash' => session('google_calendar_flash'),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function connection(): ?CalendarConnection
    {
        return CalendarConnection::query()->where('user_id', $this->user()->id)->first();
    }

    private function activeConnection(): CalendarConnection
    {
        $connection = $this->connection();

        return $connection !== null && $connection->isActive() ? $connection : abort(404);
    }

    private function usable(): bool
    {
        return app(GoogleCalendarSettings::class)->isAvailable() || $this->connection() !== null;
    }

    /**
     * Magasins de la personne : seuls leurs noms peuvent servir de nom d'agenda.
     *
     * @return array<int, string>
     */
    private function stores(): array
    {
        return $this->user()->stores()->orderBy('stores.name')->pluck('stores.name', 'stores.id')->all();
    }

    /**
     * Choix du nom de l'agenda : celui de l'organisation, ou celui d'un magasin où la personne travaille (le nom d'un
     * autre magasin n'a pas à fuiter dans son agenda).
     *
     * @return array{string, int|null}
     */
    private function validatedName(): array
    {
        $this->validate([
            'nameMode' => ['required', Rule::in([CalendarConnection::NAME_INSTANCE, CalendarConnection::NAME_STORE])],
            'storeId' => [Rule::requiredIf($this->nameMode === CalendarConnection::NAME_STORE), 'nullable', 'integer', Rule::in(array_keys($this->stores()))],
        ]);

        return $this->nameMode === CalendarConnection::NAME_STORE ? [CalendarConnection::NAME_STORE, (int) $this->storeId] : [CalendarConnection::NAME_INSTANCE, null];
    }
}
