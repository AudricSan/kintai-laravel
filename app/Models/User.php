<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\HexColor;
use App\Models\Concerns\FlushesRequestMemo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte utilisateur, et utilisateur du garde de session de Laravel (config/auth.php). Le hash du mot de passe est dans
 * la colonne password_hash.
 */
class User extends Authenticatable
{
    use FlushesRequestMemo;

    protected $table = 'users';
    protected $guarded = []; // Allow mass assignment for now to simplify migration
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class . ':#3B82F6'];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }
}