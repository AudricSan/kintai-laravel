<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StoreUser extends Model
{
    protected $table = 'store_user';
    protected $guarded = [];
    public $timestamps = false;
}
