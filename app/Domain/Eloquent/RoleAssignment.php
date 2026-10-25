<?php

declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;
use kintai\Domain\Eloquent\Concerns\FlushesRequestMemo;

final class RoleAssignment extends Model
{
    use FlushesRequestMemo;

    protected $table = 'role_assignments';
    protected $guarded = [];
    public $timestamps = false;
}
