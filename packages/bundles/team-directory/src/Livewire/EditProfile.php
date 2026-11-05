<?php

namespace Kintai\Bundles\TeamDirectory\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\TeamDirectory\Models\TeamProfile;
use Kintai\Bundles\TeamDirectory\TeamDirectoryServiceProvider;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Mon profil d'équipe » (zone des réglages personnels) : chacun écrit son propre profil et choisit d'apparaître ou non
 * dans l'annuaire. Le partage de l'e-mail et des téléphones se règle dans « Profil », ce n'est pas l'affaire du bundle.
 */
#[Title('Team profile')]
class EditProfile extends Component
{
    use ResolvesViews;

    public string $bio = '';

    public string $skills = '';

    public string $languages_spoken = '';

    public string $hobbies = '';

    public bool $show_in_directory = true;

    public function mount(): void
    {
        abort_if($this->stores() === [], 404);

        $profile = TeamProfile::query()->where('user_id', Auth::id())->first();

        if ($profile !== null) {
            $this->bio = (string) $profile->bio;
            $this->skills = (string) $profile->skills;
            $this->languages_spoken = (string) $profile->languages_spoken;
            $this->hobbies = (string) $profile->hobbies;
            $this->show_in_directory = $profile->show_in_directory;
        }
    }

    /**
     * @return array<int, string>
     */
    private function stores(): array
    {
        return app(ColleagueDirectory::class)->stores(Auth::user(), TeamDirectoryServiceProvider::FEATURE);
    }

    public function save(ActivityRecorder $activity): void
    {
        abort_if($this->stores() === [], 404);

        $validated = $this->validate([
            'bio' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'string', 'max:500'],
            'languages_spoken' => ['nullable', 'string', 'max:255'],
            'hobbies' => ['nullable', 'string', 'max:500'],
            'show_in_directory' => ['boolean'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $profile = TeamProfile::query()->firstOrNew(['user_id' => $user->id]);

        foreach (['bio', 'skills', 'languages_spoken', 'hobbies'] as $field) {
            $value = trim((string) $validated[$field]);
            $profile->{$field} = $value === '' ? null : $value;
        }
        $profile->show_in_directory = (bool) $validated['show_in_directory'];

        $changed = array_keys($profile->getDirty());
        $profile->save();

        if ($changed !== []) {
            // Les valeurs (texte libre personnel) ne sont pas journalisées : seulement les champs touchés.
            $activity->record('team-directory.profile_updated', $profile, null, ['fields' => $changed], $user);
        }

        Flux::toast(variant: 'success', text: __('Team profile saved.'));
    }

    public function render(): View
    {
        return app('view')->make($this->viewName('edit-profile'));
    }
}
