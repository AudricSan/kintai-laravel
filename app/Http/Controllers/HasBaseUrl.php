<?php

declare(strict_types=1);

namespace App\Http\Controllers;

trait HasBaseUrl
{
    protected function base(): string
    {
        return base_url();
    }
}
