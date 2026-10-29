<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BundleRegistry extends Model
{
    protected $table = 'bundle_registries';
    protected $guarded = [];
    public $timestamps = false;
}
