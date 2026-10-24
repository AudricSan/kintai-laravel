<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou des styles en ligne : un attribut style="…" ou un <style> sans nonce cesse de s'appliquer dès que la
 * Content-Security-Policy retire 'unsafe-inline' de style-src — sans aucune erreur côté serveur. Ces tests échouent
 * dès qu'une vue ou un module JS en réintroduit un.
 *
 * À faire à la place : une classe dans public/assets/css, ou style_class([...]) pour une valeur dynamique
 * (kintai\UI\InlineStyles), et <style nonce="<?= csp_nonce() ?>"> pour un bloc indispensable.
 */
final class NoInlineStyleGuardTest extends TestCase
{
    /**
     * Fichiers hors CSP : le gabarit d'e-mail est lu par un client de messagerie, qui n'accepte que des styles en ligne.
     */
    private const EXEMPT = ['app/Core/Services/PasswordResetService.php', 'app/Core/Services/NotificationMailer.php'];

    /** @return array<string, string> chemin relatif => contenu, sans les lignes de commentaire */
    private function sources(string $dir, string $extension): array
    {
        $base = dirname(__DIR__, 3);
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== $extension) {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (in_array($path, self::EXEMPT, true)) {
                continue;
            }
            $lines = [];
            foreach (file($file->getPathname()) ?: [] as $line) {
                $trim = ltrim($line);
                // Les commentaires citent volontairement style="…" pour expliquer la règle.
                if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*') || str_starts_with($trim, '#')) {
                    continue;
                }
                $lines[] = $line;
            }
            $out[$path] = implode('', $lines);
        }

        return $out;
    }

    /** @return string[] */
    private function offenders(string $dir, string $extension, string $regex): array
    {
        $found = [];
        foreach ($this->sources($dir, $extension) as $path => $code) {
            if (preg_match_all($regex, $code, $m)) {
                foreach ($m[0] as $hit) {
                    $found[] = $path . ' : ' . trim($hit);
                }
            }
        }

        return $found;
    }

    public function testNoStyleAttributeInPhpSources(): void
    {
        $this->assertSame([], $this->offenders('app', 'php', '/\sstyle\s*=\s*["\'\\\\]/i'),
            'Attribut style="…" : utiliser une classe CSS, ou style_class([...]) pour une valeur dynamique.');
    }

    public function testNoStyleAttributeBuiltInJavascript(): void
    {
        $this->assertSame([], $this->offenders('public/assets/js', 'js', '/\sstyle\s*=\s*["\'\\\\]/i'),
            'HTML construit avec style="…" : utiliser une classe, ou element.style.x (non bloqué par la CSP).');
    }

    public function testNoStyleBlockWithoutNonce(): void
    {
        $this->assertSame([], $this->offenders('app', 'php', '/<style(?![^>]*\bnonce=)[^>]*>/i'),
            'Bloc <style> sans nonce : écrire <style nonce="<?= csp_nonce() ?>">.');
    }
}
