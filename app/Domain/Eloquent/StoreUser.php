<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class StoreUser extends Model
{
    protected $table = 'store_user';
    protected $guarded = [];
    public $timestamps = false;
}
