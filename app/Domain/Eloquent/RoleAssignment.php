<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class RoleAssignment extends Model
{
    protected $table = 'role_assignments';
    protected $guarded = [];
    public $timestamps = false;
}
