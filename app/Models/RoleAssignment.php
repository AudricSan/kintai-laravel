<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FlushesRequestMemo;

final class RoleAssignment extends Model
{
    use FlushesRequestMemo;

    protected $table = 'role_assignments';
    protected $guarded = [];
    public $timestamps = false;
}
