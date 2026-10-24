<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class StoreImportSetting extends Model
{
    protected $table = 'store_import_settings';
    protected $guarded = [];
    public $timestamps = false;
}
