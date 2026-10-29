<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StoreImportSetting extends Model
{
    protected $table = 'store_import_settings';
    protected $guarded = [];
    public $timestamps = false;
}
