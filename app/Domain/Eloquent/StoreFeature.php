<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class StoreFeature extends Model
{
    protected $table = 'store_features';
    protected $guarded = [];
    public $timestamps = false;
}
