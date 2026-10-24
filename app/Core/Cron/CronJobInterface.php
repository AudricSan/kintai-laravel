<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use kintai\Core\Request;
use kintai\Core\Response;

interface CronJobInterface
{
    public function getName(): string;

    public function run(Request $request): Response;
}
