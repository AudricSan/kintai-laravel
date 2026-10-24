<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;
use kintai\Domain\Eloquent\Casts\HexColor;

final class Role extends Model
{
    protected $table = 'roles';
    protected $guarded = [];
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class];
}
