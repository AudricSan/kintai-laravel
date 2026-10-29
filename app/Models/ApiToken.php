<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ApiToken extends Model
{
    protected $table = 'api_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
