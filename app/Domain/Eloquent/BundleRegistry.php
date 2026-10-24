<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class BundleRegistry extends Model
{
    protected $table = 'bundle_registries';
    protected $guarded = [];
    public $timestamps = false;
}
