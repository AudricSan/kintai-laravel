<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FlushesRequestMemo;
use App\Models\Casts\HexColor;

final class User extends Model
{
    use FlushesRequestMemo;

    protected $table = 'users';
    protected $guarded = []; // Allow mass assignment for now to simplify migration
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class . ':#3B82F6'];
}
