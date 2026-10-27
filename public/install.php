<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require BASE_PATH . '/app/Core/helpers.php';
kintai_normalize_script_name();

use kintai\Core\Container;
use kintai\Core\Install\InstallFailure;
use kintai\Core\Install\InstallInput;
use kintai\Core\Install\InstallRequirements;
use kintai\Core\Install\WebInstaller;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\TranslationService;

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

// __() passe par le TranslationService du conteneur ; l'application n'est pas encore démarrée, il est fourni ici.
$registerTranslations = static function (string $locale) use ($languageRepository): void {
    $translations = new TranslationService(new JsonTranslationRepository(BASE_PATH . '/lang'), $languageRepository);
    $translations->setLocale($locale);
    Container::getInstance()->instance(TranslationService::class, $translations);
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
    $input = InstallInput::fromPost($_POST, $defaultLocale, $_FILES);
    $errors = array_map(static fn (string $key): string => __($key), $input->errors($languageCodes, $requirements));

    // Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES, seul le volume annoncé reste.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $_POST = ['install_mode' => InstallInput::MODE_IMPORT];
        $errors = [__('install_import_error_file_too_large')];
    }

    if ($errors === [] && $input->mode === InstallInput::MODE_IMPORT) {
        try {
            // Les comptes et leurs mots de passe viennent de l'instance importée : connexion habituelle ensuite.
            $publicUrl = PublicUrlResolver::normalize($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . $installBase);
            $installer = new WebInstaller(BASE_PATH);
            $upload = $input->importUpload;
            if ($input->importFrom === InstallInput::IMPORT_FROM_FILE && $upload !== null && is_uploaded_file($upload['tmp_name'])) {
                $result = ['mode' => InstallInput::MODE_IMPORT] + $installer->importFile($upload['tmp_name'], $upload['name'], $input->locale, $publicUrl);
            } elseif ($input->importFrom === InstallInput::IMPORT_FROM_FOLDER) {
                $result = ['mode' => InstallInput::MODE_IMPORT] + $installer->import($input->importPath, $input->locale, $publicUrl);
            } else {
                throw new InstallFailure('install_import_error_file_upload');
            }
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

            // Connexion de l'Owner (même session que l'application), puis assistant de configuration.
            \kintai\Core\Middleware\SessionMiddleware::start($scheme === 'https');
            Container::getInstance()->make(\kintai\Core\Auth\AuthService::class)->attempt($input->email, $input->password);

            // Avec des comptes de démonstration, la page reste affichée : ils y sont listés.
            if ($result['demo_accounts'] === []) {
                header('Refresh: 3; URL=' . $installBase . '/admin/setup');
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
    // Taille maximale d'un fichier envoyé : la plus petite des deux limites de PHP.
    'upload_limit'     => (static function (): string {
        $bytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtoupper(substr(trim($v), -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);
        $max = $limits === [] ? 0 : min($limits);
        return $max >= 1 << 30 ? round($max / (1 << 30), 1) . ' Go' : ($max >= 1 << 20 ? round($max / (1 << 20)) . ' Mo' : round($max / 1024) . ' Ko');
    })(),
    'demo_password'    => \kintai\Core\Database\DemoDataSeeder::PASSWORD,
    'old'              => $_POST,
    'default_host'     => (string) env('DB_HOST', '127.0.0.1'),
    'default_port'     => (string) env('DB_PORT', '3306'),
    'default_database' => (string) env('DB_DATABASE', 'kintai'),
    'default_username' => (string) env('DB_USERNAME', 'root'),
];
(static function (array $__vars): void {
    extract($__vars);
    require BASE_PATH . '/app/UI/View/install/index.php';
})($view);
