<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RememberToken extends Model
{
    protected $table = 'remember_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
