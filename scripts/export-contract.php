<?php

declare(strict_types=1);

/**
 * Instantané du contrat de bundle (contract.snapshot.json).
 *
 *   php scripts/export-contract.php          affiche l'état et l'écart avec l'instantané versionné
 *   php scripts/export-contract.php --write  régénère contract.snapshot.json (refusé si la règle de version est violée)
 *
 * La CI échoue (ContractSnapshotTest) tant que l'instantané versionné ne correspond pas au contrat réel : tout
 * changement du contrat est donc visible dans la revue, et un retrait ou changement d'une entrée stable exige une
 * version majeure. Voir tests/Support/ContractSnapshot.php.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';

use Tests\Support\ContractSnapshot;

$file = BASE_PATH . '/contract.snapshot.json';
$new  = ContractSnapshot::export();
$old  = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

if ($old !== null) {
    $violations = ContractSnapshot::versionViolations($old, $new);
    if ($violations !== []) {
        fwrite(STDERR, "Règle de version du contrat violée :\n - " . implode("\n - ", $violations) . "\n");
        exit(1);
    }
}

$json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

if (in_array('--write', $argv, true)) {
    file_put_contents($file, $json);
    echo "contract.snapshot.json écrit (contrat {$new['version']}).\n";
    exit(0);
}

echo $old === $new ? "Instantané à jour (contrat {$new['version']}).\n" : "Instantané différent du contrat réel : lancez --write.\n";
exit($old === $new ? 0 : 2);
