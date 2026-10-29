<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Seul public/ doit être servi. Si le serveur web pointe sur la racine du dépôt (domaine sur www/ d'un
 * hébergement mutualisé, ou htdocs/Kintai atteint via http://localhost/Kintai/), .env, la base SQLite,
 * les sauvegardes et le code source seraient téléchargeables — vérifié sur une installation de
 * développement (HTTP 200 sur /.env et /storage/app/database.sqlite). Le .htaccess racine réécrit donc
 * chaque requête en interne vers public/ : une URL n'est jamais cherchée ailleurs. Ces tests figent ce
 * garde-fou, ainsi que celui de storage/.
 */
final class WebRootProtectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    private function rootHtaccess(): string
    {
        $file = $this->root . '/.htaccess';
        $this->assertFileExists($file, 'Le .htaccess de la racine (réécriture vers public/) a disparu.');

        return (string) file_get_contents($file);
    }

    /**
     * Les RewriteRule du bloc mod_rewrite du .htaccess racine, sous forme [motif, substitution, drapeaux].
     *
     * @return list<array{string, string, string}>
     */
    private function rewriteRules(): array
    {
        $this->assertSame(
            1,
            preg_match('~<IfModule mod_rewrite\.c>(.*?)</IfModule>~s', $this->rootHtaccess(), $block),
            'Bloc <IfModule mod_rewrite.c> introuvable dans le .htaccess racine.',
        );
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]/m', $block[1], $m, PREG_SET_ORDER);

        return array_map(static fn(array $r): array => [$r[1], $r[2], $r[3]], $m);
    }

    /**
     * Rejoue les règles comme Apache (réécriture par répertoire : tant qu'une règle réécrit sans [L] final
     * puis que la requête est relancée) et renvoie le chemin finalement servi, relatif à la racine du dépôt.
     */
    private function resolve(string $path): string
    {
        for ($pass = 0; $pass < 5; $pass++) {
            foreach ($this->rewriteRules() as [$pattern, $substitution, $flags]) {
                if (preg_match('~' . $pattern . '~', $path, $m) !== 1) {
                    continue;
                }
                $this->assertStringNotContainsString('R', $flags, "Une redirection révélerait /public dans l'URL du visiteur.");
                if ($substitution === '-') {
                    return $path;
                }
                $path = str_replace('$1', $m[1] ?? '', $substitution);
                continue 2;
            }

            return $path;
        }

        $this->fail("Boucle de réécriture pour « $path ».");
    }

    /** @return array<string, array{string}> */
    public static function sensitivePaths(): array
    {
        return [
            'env'                => ['.env'],
            'base SQLite'        => ['storage/app/database.sqlite'],
            'sauvegardes'        => ['storage/backups/backup_2026.zip'],
            'journaux'           => ['storage/logs/error.log'],
            'code source'        => ['app/Application.php'],
            'composer'           => ['composer.json'],
            'configuration'      => ['config/database.local.php'],
            'dépôt git'          => ['.git/config'],
            'migrations'         => ['database/migrations/php/x.php'],
            'tests'              => ['tests/Unit/Core/RoutesTest.php'],
            'faux ami "public"'  => ['publicity/secret.txt'],
            'public déguisé'     => ['storage/public/x'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitivePaths')]
    public function testSensitivePathsAreOnlyEverLookedUpUnderPublic(string $path): void
    {
        $served = $this->resolve($path);

        $this->assertSame('public/' . $path, $served, "« $path » doit être cherché sous public/, jamais à la racine.");
        $this->assertFileDoesNotExist($this->root . '/' . $served, "« $served » ne doit pas exister dans public/.");
    }

    public function testRepositoryRootIsServedFromPublic(): void
    {
        $this->assertSame('public/', $this->resolve(''));
    }

    /** @return array<string, array{string}> */
    public static function publicPaths(): array
    {
        return [
            'front controller' => ['public/index.php'],
            'css'              => ['public/assets/css/app.css'],
            'js'               => ['public/assets/js/app.js'],
            'installeur'       => ['public/install.php'],
            "pages d'erreur"  => ['public/errors/404.html'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPaths')]
    public function testDirectPublicPathsAreLeftUntouched(string $path): void
    {
        $this->assertSame($path, $this->resolve($path), "« $path » ne doit pas être réécrit.");
    }

    /** @return array<string, array{string, string}> */
    public static function cleanUrls(): array
    {
        return [
            'page'      => ['login', 'public/login'],
            'asset'     => ['assets/css/app.css', 'public/assets/css/app.css'],
            'installeur' => ['install.php', 'public/install.php'],
            'stockage'  => ['storage/photos/a.jpg', 'public/storage/photos/a.jpg'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cleanUrls')]
    public function testCleanUrlsAreServedFromPublic(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->resolve($url));
    }

    public function testEverythingIsDeniedWithoutModRewrite(): void
    {
        // Sans mod_rewrite la réécriture n'a pas lieu et la racine du dépôt serait exposée en entier.
        $this->assertMatchesRegularExpression(
            '~<IfModule !mod_rewrite\.c>.*Require all denied.*Deny from all.*</IfModule>\s*$~s',
            $this->rootHtaccess(),
        );
    }

    public function testDirectoryListingIsDisabledAtTheRoot(): void
    {
        $this->assertMatchesRegularExpression('/^\s*Options\s+-Indexes/m', $this->rootHtaccess());
    }

    /**
     * Expression de la condition <If> de storage/.htaccess (REQUEST_URI refusé), convertie en regex PCRE.
     *
     * @return list<string>
     */
    private function storageDenyPatterns(): array
    {
        $file = $this->root . '/storage/.htaccess';
        $this->assertFileExists($file, 'storage/.htaccess a disparu (refus de l\'accès direct).');
        $content = (string) file_get_contents($file);

        $this->assertStringContainsString('Require all denied', $content);
        $this->assertStringContainsString('Deny from all', $content, 'Repli Apache 2.2 manquant.');
        $this->assertSame(
            1,
            preg_match('~<If "([^"\n]+)">~', $content, $if),
            'La condition <If> de storage/.htaccess a disparu.',
        );
        preg_match_all('~m#(.+?)#i~', $if[1], $m);
        $this->assertNotEmpty($m[1]);

        return array_map(static fn(string $p): string => '#' . $p . '#i', $m[1]);
    }

    private function storageIsDenied(string $uri): bool
    {
        foreach ($this->storageDenyPatterns() as $pattern) {
            if (preg_match($pattern, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{string}> */
    public static function physicalStoragePaths(): array
    {
        return [
            'base SQLite'        => ['/storage/app/database.sqlite'],
            'sauvegardes'        => ['/storage/backups/backup_2026.zip'],
            'journaux'           => ['/storage/logs/error.log'],
            'cache'              => ['/storage/cache/x'],
            'bundles installés'  => ['/storage/bundles/installed.json'],
            'uploads en direct'  => ['/storage/uploads/img/1/a.jpg'],
            'marqueur install'   => ['/storage/installed.lock'],
            'casse différente'   => ['/STORAGE/APP/database.sqlite'],
            'sous-dossier'       => ['/Kintai/storage/app/database.sqlite'],
            'dossier seul'       => ['/storage/app'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('physicalStoragePaths')]
    public function testStorageDeniesDirectAccessToPhysicalContent(string $uri): void
    {
        $this->assertTrue($this->storageIsDenied($uri), "« $uri » doit rester refusé par storage/.htaccess.");
    }

    /** @return array<string, array{string}> */
    public static function virtualStorageUrls(): array
    {
        return [
            'photo'            => ['/storage/img/3/12/photo.jpg'],
            'avatar'           => ['/storage/avatars/u1.webp'],
            'import'           => ['/storage/5/import.xlsx'],
            'sous-dossier'     => ['/Kintai/storage/img/3/12/photo.jpg'],
            'dossier "app" plus loin' => ['/storage/img/3/app/photo.jpg'],
        ];
    }

    /**
     * Apache évalue storage/.htaccess pour l'URL originale, avant la réécriture racine vers public/ : un refus
     * inconditionnel renvoyait 403 sur les photos servies par StorageFileController dès que le serveur pointe sur
     * la racine du dépôt (sans /public dans l'URL). Ces URL ne correspondent à aucun fichier réel et doivent passer.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('virtualStorageUrls')]
    public function testStorageLetsVirtualUrlsReachTheFrontController(string $uri): void
    {
        $this->assertFalse($this->storageIsDenied($uri), "« $uri » ne doit pas être bloqué par Apache (URL servie par le contrôleur).");
    }

    public function testStorageHtaccessIsNotIgnoredByGit(): void
    {
        // storage/* est ignoré : sans cette exception, le fichier n'atteindrait jamais un dépôt cloné.
        $this->assertMatchesRegularExpression('#^!/storage/\.htaccess\s*$#m', (string) file_get_contents($this->root . '/.gitignore'));
    }

    public function testPublicHtaccessStillRoutesToTheFrontController(): void
    {
        // Le .htaccess racine laisse passer public/ ; c'est public/.htaccess qui route vers index.php.
        $this->assertStringContainsString('index.php', (string) file_get_contents($this->root . '/public/.htaccess'));
    }
}
