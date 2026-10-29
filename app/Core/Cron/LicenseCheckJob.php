<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use kintai\Core\Services\LicenseClientService;

/** Revalide périodiquement la licence active auprès du serveur distant — no-op si aucune clé n'est enregistrée (voir LicenseClientService). */
final class LicenseCheckJob implements CronJobInterface
{
    public function __construct(
        private readonly LicenseClientService $license,
    ) {}

    public function getName(): string
    {
        return 'license-check';
    }

    public function run(Request $request): Response
    {
        $result = $this->license->refresh();

        return response()->json([
            'ok'      => true,
            'checked' => $result !== null,
            'valid'   => (bool) ($result['valid'] ?? false),
        ]);
    }
}
