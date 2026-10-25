<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;
use kintai\Domain\Eloquent\Concerns\FlushesRequestMemo;

final class RolePermission extends Model
{
    use FlushesRequestMemo;

    protected $table = 'role_permissions';
    protected $guarded = [];
    public $timestamps = false;
}
