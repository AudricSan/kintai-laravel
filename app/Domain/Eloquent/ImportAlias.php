<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class ImportAlias extends Model
{
    protected $table = 'import_aliases';
    protected $guarded = [];
    public $timestamps = false;
}
