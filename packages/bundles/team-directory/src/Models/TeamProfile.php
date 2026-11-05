<?php

namespace Kintai\Bundles\TeamDirectory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Profil d'équipe d'un employé (1-1 avec users), écrit par lui seul. Sans ligne : profil vide, visible dans l'annuaire.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $bio
 * @property string|null $skills
 * @property string|null $languages_spoken
 * @property string|null $hobbies
 * @property bool $show_in_directory
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'bio', 'skills', 'languages_spoken', 'hobbies', 'show_in_directory'])]
class TeamProfile extends Model
{
    protected $table = 'team_directory_profiles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_in_directory' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEmpty(): bool
    {
        return collect([$this->bio, $this->skills, $this->languages_spoken, $this->hobbies])
            ->every(fn (?string $value): bool => $value === null || trim($value) === '');
    }
}
