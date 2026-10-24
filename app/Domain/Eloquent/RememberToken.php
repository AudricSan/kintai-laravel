<?php
declare(strict_types=1);

namespace kintai\Domain\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class RememberToken extends Model
{
    protected $table = 'remember_tokens';
    protected $guarded = [];
    public $timestamps = false;
}
