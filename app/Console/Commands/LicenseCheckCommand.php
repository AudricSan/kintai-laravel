<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LicenseClientService;
use Illuminate\Console\Command;

/** Revalide périodiquement la licence active auprès du serveur distant — sans effet si aucune clé n'est enregistrée. */
final class LicenseCheckCommand extends Command
{
    protected $signature = 'kintai:license-check';

    protected $description = 'Vérification de la licence';

    public function handle(LicenseClientService $license): int
    {
        $result = $license->refresh();
        $this->line(json_encode(['ok' => true, 'checked' => $result !== null, 'valid' => (bool) ($result['valid'] ?? false)]));

        return self::SUCCESS;
    }
}
