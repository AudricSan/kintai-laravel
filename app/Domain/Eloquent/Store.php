<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class Store extends Model
{
    protected $table = 'stores';
    protected $guarded = [];
    public $timestamps = false;
}
