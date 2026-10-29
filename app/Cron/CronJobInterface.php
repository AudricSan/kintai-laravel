<?php

declare(strict_types=1);

namespace App\Cron;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface CronJobInterface
{
    public function getName(): string;

    public function run(Request $request): Response;
}
