<?php

namespace App\Models;

use Database\Factories\UserNavPrefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Préférences de navigation d'un utilisateur : entrées masquées et entrées de la barre mobile du bas.
 * `bottom_nav_items` null = barre par défaut.
 *
 * @property int $id
 * @property int $user_id
 * @property list<string>|null $hidden_items
 * @property list<string>|null $bottom_nav_items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'hidden_items', 'bottom_nav_items'])]
class UserNavPref extends Model
{
    /** @use HasFactory<UserNavPrefFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_items' => 'array',
            'bottom_nav_items' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
