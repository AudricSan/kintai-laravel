<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ImportAlias extends Model
{
    protected $table = 'import_aliases';
    protected $guarded = [];
    public $timestamps = false;
}
