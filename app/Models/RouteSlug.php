<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RouteSlug extends Model
{
    protected $table = 'route_slugs';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'entity_id'  => 'integer',
        'is_current' => 'boolean',
        'is_manual'  => 'boolean',
    ];
}
