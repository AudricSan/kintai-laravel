<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FlushesRequestMemo;
use App\Models\Casts\HexColor;

final class Role extends Model
{
    use FlushesRequestMemo;

    protected $table = 'roles';
    protected $guarded = [];
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class];
}
