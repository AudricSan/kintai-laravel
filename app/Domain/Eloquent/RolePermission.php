<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class RolePermission extends Model
{
    protected $table = 'role_permissions';
    protected $guarded = [];
    public $timestamps = false;
}
