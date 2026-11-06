<?php

namespace Kintai\Bundles\GoogleCalendar\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarClient;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\GoogleRedirectUri;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Contracts\Activity\ActivityRecorder;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Réglages Google Calendar de l'instance (Owner seulement, `manage-settings`) : identifiants du projet Google Cloud,
 * activation de la liaison et test des identifiants. Chaque instance auto-hébergée crée son propre projet. Le secret
 * client est chiffré en base et n'est jamais renvoyé au navigateur : le champ reste vide, le laisser vide conserve le
 * secret enregistré. Chaque action revérifie le droit.
 */
#[Title('Google Calendar settings')]
class Settings extends Component
{
    use ResolvesViews;

    public bool $enabled = false;

    public string $clientId = '';

    /** Saisie seulement : jamais remplie depuis la base. */
    public string $clientSecret = '';

    /** Résultat du dernier test : ok, invalid_client, unreachable, incomplete ou vide. */
    public string $check = '';

    public function mount(GoogleCalendarSettings $settings): void
    {
        $this->authorizeOwner();

        $this->enabled = $settings->isEnabled();
        $this->clientId = $settings->clientId();
    }

    public function save(GoogleCalendarSettings $settings, ActivityRecorder $activity): void
    {
        $this->authorizeOwner();

        // Un copier-coller depuis la console Google traîne souvent des espaces autour.
        $this->clientId = trim($this->clientId);
        $this->clientSecret = trim($this->clientSecret);

        $validated = $this->validate([
            'enabled' => ['boolean'],
            'clientId' => ['nullable', 'string', 'max:255', 'regex:/^\S*$/'],
            'clientSecret' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['enabled' => $settings->isEnabled(), 'client_id' => $settings->clientId()];
        $newSecret = trim($validated['clientSecret'] ?? '') !== '';

        $settings->save((bool) $validated['enabled'], (string) ($validated['clientId'] ?? ''), $validated['clientSecret'] ?? null);

        // Ni le secret ni l'identifiant ne sont journalisés : seulement ce qui a changé.
        $activity->record('google_calendar.settings_updated', null, null, [
            'enabled' => (bool) $validated['enabled'],
            'client_id_changed' => $before['client_id'] !== $settings->clientId(),
            'credentials_replaced' => $newSecret,
        ]);

        $this->clientSecret = '';
        $this->check = '';
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /**
     * Vérifie l'identifiant et le secret enregistrés auprès de Google.
     */
    public function test(GoogleCalendarSettings $settings, GoogleCalendarClient $client, GoogleRedirectUri $redirect): void
    {
        $this->authorizeOwner();

        $this->check = $settings->clientId() === '' || ! $settings->hasSecret() ? 'incomplete' : $client->checkCredentials($redirect->resolve());
    }

    public function render(GoogleCalendarSettings $settings, GoogleRedirectUri $redirect): View
    {
        return app('view')->make($this->viewName('settings'), [
            'hasSecret' => $settings->hasSecret(),
            'available' => $settings->isAvailable(),
            'redirectUri' => $redirect->resolve(),
            'connections' => CalendarConnection::query()->active()->count(),
        ]);
    }

    private function authorizeOwner(): void
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless(Gate::forUser($user)->allows('manage-settings'), 403);
    }
}
