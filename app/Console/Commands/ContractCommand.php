<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tests\Support\ContractSnapshot;

/**
 * Instantané du contrat de bundle (contract.snapshot.json).
 *
 *   php artisan kintai:contract           affiche l'état et l'écart avec l'instantané versionné
 *   php artisan kintai:contract --write   régénère contract.snapshot.json (refusé si la règle de version est violée)
 *
 * La CI échoue (ContractSnapshotTest) tant que l'instantané versionné ne correspond pas au contrat réel : tout
 * changement du contrat est donc visible dans la revue, et un retrait ou changement d'une entrée stable exige une
 * version majeure. Outil de développement : il s'appuie sur tests/Support/ContractSnapshot.php.
 */
final class ContractCommand extends Command
{
    protected $signature = 'kintai:contract {--write : Régénère contract.snapshot.json}';

    protected $description = 'Compare le contrat de bundle à son instantané (--write : le régénère)';

    public function handle(): int
    {
        $file = base_path('contract.snapshot.json');
        $new  = ContractSnapshot::export();
        $old  = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if ($old !== null) {
            $violations = ContractSnapshot::versionViolations($old, $new);
            if ($violations !== []) {
                $this->error("Règle de version du contrat violée :\n - " . implode("\n - ", $violations));

                return self::FAILURE;
            }
        }

        if ($this->option('write')) {
            file_put_contents($file, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
            $this->line("contract.snapshot.json écrit (contrat {$new['version']}).");

            return self::SUCCESS;
        }

        if ($old === $new) {
            $this->line("Instantané à jour (contrat {$new['version']}).");

            return self::SUCCESS;
        }

        $this->line('Instantané différent du contrat réel : lancez avec --write.');

        return 2;
    }
}
