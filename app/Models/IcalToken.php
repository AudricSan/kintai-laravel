<?php

namespace App\Models;

use Database\Factories\IcalTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jeton du flux iCal d'un employé pour un magasin. Seule l'empreinte SHA-256 du jeton est conservée.
 *
 * @property int $id
 * @property int $user_id
 * @property int $store_id
 * @property string $token_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Store $store
 */
#[Fillable(['user_id', 'store_id', 'token_hash'])]
class IcalToken extends Model
{
    /** @use HasFactory<IcalTokenFactory> */
    use HasFactory;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
