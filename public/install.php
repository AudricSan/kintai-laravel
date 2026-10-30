<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require BASE_PATH . '/app/Support/helpers.php';
kintai_normalize_script_name();

use Illuminate\Container\Container;
use App\Install\InstallFailure;
use App\Install\InstallInput;
use App\Install\InstallRequirements;
use App\Install\WebInstaller;
use App\Repositories\JsonLanguageRepository;
use App\Services\InstanceLocale;
use App\Services\PublicUrlResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

// ─── Déjà installé ────────────────────────────────────────────────────────────

$installedLockFile = BASE_PATH . '/storage/installed.lock';
if (file_exists($installedLockFile) || kintai_installed_via_database(BASE_PATH)) {
    // Le marqueur peut avoir disparu (voir helpers.php:kintai_installed_via_database) sans
    // que la base ne soit réellement désinstallée — le régénérer avant de rediriger évite de
    // revenir systématiquement sur ce formulaire d'installation.
    if (!file_exists($installedLockFile)) {
        @file_put_contents($installedLockFile, bin2hex(random_bytes(32)));
    }
    header('Location: ' . base_url() . '/');
    exit;
}

// ─── Langue de la page ────────────────────────────────────────────────────────

// Langues actives de lang/languages.json, lues avant le démarrage de l'application : l'une devient la langue de
// l'instance (APP_LOCALE, voir InstanceLocale), celle de tous les textes tant qu'un utilisateur n'a pas choisi la sienne.
$languageRepository = new JsonLanguageRepository(BASE_PATH . '/lang');
$installLanguages   = $languageRepository->findAllActive();
$languageCodes      = array_column($installLanguages, 'code');
$defaultLocale      = InstanceLocale::defaultForNewInstance($languageRepository);

/** Langue de la page : celle choisie dans le formulaire, sinon la préférée du navigateur parmi les langues actives. */
$pageLocale = (static function () use ($languageCodes, $defaultLocale): string {
    $posted = is_string($_POST['app_locale'] ?? null) ? $_POST['app_locale'] : '';
    if (in_array($posted, $languageCodes, true)) {
        return $posted;
    }
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
        if (in_array($code, $languageCodes, true)) {
            return $code;
        }
    }
    return $defaultLocale;
})();

// __() passe par le traducteur de Laravel (lang/{locale}.json) ; l'application n'est pas encore démarrée, il est fourni ici.
$registerTranslations = static function (string $locale): void {
    $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/lang'), $locale);
    $translator->setFallback($locale);
    Container::getInstance()->instance('translator', $translator);
    \Illuminate\Support\Facades\Lang::clearResolvedInstance('translator');
};
$registerTranslations($pageLocale);

// ─── Installation ─────────────────────────────────────────────────────────────

$requirements = new InstallRequirements(BASE_PATH);
$errors       = [];
$result       = null;
$scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$installBase  = base_url();

// Changement de langue (sélecteur du formulaire) : la page est seulement réaffichée, rien n'est installé.
$switchingLanguage = isset($_POST['switch_language']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$switchingLanguage) {
    $input = InstallInput::fromPost($_POST, $defaultLocale);
    $errors = array_map(static fn (string $key): string => __($key), $input->errors($languageCodes, $requirements));

    if ($errors === [] && $input->mode === InstallInput::MODE_IMPORT) {
        try {
            // Les comptes et leurs mots de passe viennent de l'instance importée : connexion habituelle ensuite.
            $publicUrl = PublicUrlResolver::normalize($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . $installBase);
            $result = ['mode' => InstallInput::MODE_IMPORT] + (new WebInstaller(BASE_PATH))->import($input->importPath, $input->locale, $publicUrl);
            $registerTranslations($pageLocale);
        } catch (InstallFailure $e) {
            $registerTranslations($pageLocale);
            $errors[] = __($e->messageKey, $e->replace);
        }
    } elseif ($errors === []) {
        try {
            // Adresse par laquelle l'administrateur installe : la bonne dans l'immense majorité des cas.
            $publicUrl = PublicUrlResolver::normalize($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . $installBase);
            $result = ['mode' => InstallInput::MODE_FRESH] + (new WebInstaller(BASE_PATH))->install($input, $publicUrl);

            // Le démarrage de l'application a remplacé les traductions : celles de la page sont reprises.
            $registerTranslations($pageLocale);

            // Avec des comptes de démonstration, la page reste affichée : ils y sont listés.
            if ($result['demo_accounts'] === []) {
                header('Refresh: 3; URL=' . $installBase . '/login');
            }
        } catch (InstallFailure $e) {
            $registerTranslations($pageLocale);
            $errors[] = __($e->messageKey, $e->replace);
        }
    }
}

// ─── Affichage ────────────────────────────────────────────────────────────────

// Aucun script ni style en ligne sur cette page : politique stricte, sans nonce.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$view = [
    'BASE_URL'         => $installBase,
    'locale'           => $pageLocale,
    'languages'        => $installLanguages,
    'checks'           => $requirements->checks(),
    'can_install'      => $requirements->canInstall(),
    'drivers'          => $requirements->availableDrivers(),
    'errors'           => $errors,
    'result'           => $result,
    'setup_url'        => $installBase . '/admin/setup',
    'login_url'        => $installBase . '/login',
    'demo_password'    => \App\Database\DemoDataSeeder::PASSWORD,
    'old'              => $_POST,
    'default_host'     => (string) env('DB_HOST', '127.0.0.1'),
    'default_port'     => (string) env('DB_PORT', '3306'),
    'default_database' => (string) env('DB_DATABASE', 'kintai'),
    'default_username' => (string) env('DB_USERNAME', 'root'),
];
(static function (array $__vars): void {
    extract($__vars);
    require BASE_PATH . '/resources/views/install/index.php';
})($view);
