<?php

namespace Kintai\Bundles\GoogleCalendar;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\GoogleCalendar\Console\SyncCommand;
use Kintai\Bundles\GoogleCalendar\Livewire\Profile;
use Kintai\Bundles\GoogleCalendar\Livewire\Settings;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftRestored;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Livewire\Livewire;

/**
 * Synchronisation Google Agenda : les shifts et les congés approuvés de chaque employé apparaissent dans un agenda dédié
 * que Kintai crée chez Google, de Kintai vers Google uniquement. Libre-service (chacun lie son propre compte, comme le
 * profil) : pas de permission à attribuer. L'Owner saisit les identifiants du projet Google Cloud de l'instance
 * (`Settings`, secret chiffré) et active la fonction. Tout le code Google vit ici : le Core n'en contient aucun.
 */
class GoogleCalendarServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'google-calendar';

    /** Type de notification : le jeton de l'utilisateur est révoqué ou expiré. */
    public const NOTIFICATION = 'google_calendar_revoked';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Google Calendar';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'One-way sync of the shifts and approved time off of each employee to a dedicated Google calendar.';
    }

    public function requiredContract(): string
    {
        return '^1.5';
    }

    protected function bootBundle(): void
    {
        $this->commands([SyncCommand::class]);

        if ($this->isEnabled()) {
            Livewire::component('google-calendar.profile', Profile::class);
            Livewire::component('google-calendar.settings', Settings::class);

            // Planifiée chaque minute (planificateur de Laravel ou `/cron/run`) seulement tant que le bundle est activé.
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('google-calendar:sync')->everyMinute()->name('google-calendar:sync')->withoutOverlapping());
        }

        $this->settings(...GoogleCalendarSettings::definitions());

        // Page de l'Owner : identifiants du projet Google Cloud et activation.
        $this->navigation(new NavigationItem(
            key: 'google-calendar-settings', label: 'Google Calendar settings', route: 'google-calendar.settings', icon: 'calendar-days',
            section: NavigationRegistry::SECTION_SYSTEM, order: 80, permission: 'manage-settings',
        ));

        // Page de chacun, proposée quand l'Owner a activé la fonction ou quand la personne est déjà liée.
        $this->profileSection('google-calendar', 'Google Calendar', 'google-calendar.profile', 40, function (Authenticatable $user): bool {
            return $user instanceof User
                && ($this->app->make(GoogleCalendarSettings::class)->isAvailable() || CalendarConnection::query()->where('user_id', $user->id)->exists());
        });

        $this->notificationTypes(new NotificationType(self::NOTIFICATION, 'Calendar sync interrupted', 'system', 'calendar-days', push: true, mail: true));

        foreach ([
            'google_calendar.connected' => 'Google Calendar connected',
            'google_calendar.disconnected' => 'Google Calendar disconnected',
            'google_calendar.revoked' => 'Google Calendar access lost',
            'google_calendar.settings_updated' => 'Google Calendar settings updated',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Google Calendar');
        }

        // Temps réel : chaque écriture d'un shift met sa source en file pour l'employé lié (aucun appel à Google ici).
        $this->listen(ShiftCreated::class, [QueueShiftChanges::class, 'created']);
        $this->listen(ShiftUpdated::class, [QueueShiftChanges::class, 'updated']);
        $this->listen(ShiftDeleted::class, [QueueShiftChanges::class, 'deleted']);
        $this->listen(ShiftRestored::class, [QueueShiftChanges::class, 'restored']);
        $this->listen(ShiftReplaced::class, [QueueShiftChanges::class, 'replaced']);

        // La liaison est une donnée personnelle : à l'anonymisation du compte, jeton révoqué, liens et file supprimés.
        $this->listen(UserAnonymized::class, function (UserAnonymized $event): void {
            foreach (CalendarConnection::query()->where('user_id', $event->userId)->get() as $connection) {
                $this->app->make(CalendarSync::class)->disconnect($connection);
            }
        });
    }
}
