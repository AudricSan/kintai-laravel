<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de la Content-Security-Policy (SecurityHeadersMiddleware) : sans 'unsafe-inline', un attribut
 * onclick=…, un lien javascript: ou un <script> sans nonce est bloqué par le navigateur — sans aucune erreur
 * côté serveur, donc sans test qui échoue. Ces tests échouent à la place, dès qu'une vue en réintroduit un.
 *
 * À faire à la place : data-on-click / data-confirm / data-submit-on-change… (public/assets/js/modules/csp-actions.js)
 * et <script nonce="<?= csp_nonce() ?>"> pour un script inline indispensable.
 */
final class NoInlineScriptGuardTest extends TestCase
{
    /** @return array<string, string> chemin relatif => contenu, sans les lignes de commentaire */
    private function sources(): array
    {
        $base = dirname(__DIR__, 3);
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $lines = [];
            foreach (file($file->getPathname()) ?: [] as $line) {
                $trim = ltrim($line);
                // Les commentaires citent volontairement onclick=, javascript:… pour expliquer la règle.
                if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*') || str_starts_with($trim, '#')) {
                    continue;
                }
                $lines[] = $line;
            }
            $out[str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1))] = implode('', $lines);
        }

        return $out;
    }

    /** @return string[] */
    private function offenders(string $regex): array
    {
        $found = [];
        foreach ($this->sources() as $path => $code) {
            if (preg_match_all($regex, $code, $m)) {
                foreach ($m[0] as $hit) {
                    $found[] = $path . ' : ' . trim($hit);
                }
            }
        }

        return $found;
    }

    public function testNoInlineEventHandlerAttribute(): void
    {
        $this->assertSame([], $this->offenders('/\bon(?:click|dblclick|change|input|submit|reset|focus|blur|keyup|keydown|keypress|load|error|mouse\w+|touch\w+|drag\w+|drop|toggle|select|scroll|resize)\s*=\s*\\\\?["\']/i'));
    }

    public function testNoInlineEventHandlerPassedToComponentAttrs(): void
    {
        $this->assertSame([], $this->offenders('/[\'"]on[a-z]+[\'"]\s*=>/i'));
    }

    public function testNoJavascriptUrl(): void
    {
        $this->assertSame([], $this->offenders('/(?:href|src|action|formaction)\s*=\s*\\\\?["\']\s*javascript:/i'));
    }

    public function testEveryExecutableInlineScriptCarriesTheNonce(): void
    {
        $bad = [];
        foreach ($this->sources() as $path => $code) {
            // <script …> sans src=, qui n'est pas un bloc de données JSON (non exécuté), sans nonce=.
            preg_match_all('/<script\b([^>]*)>/i', $code, $matches);
            foreach ($matches[1] as $attrs) {
                if (str_contains($attrs, 'src=') || str_contains($attrs, 'application/json') || str_contains($attrs, 'nonce=')) {
                    continue;
                }
                $bad[] = $path . ' : <script' . rtrim($attrs) . '>';
            }
        }

        $this->assertSame([], $bad);
    }

    public function testTheGuardActuallyDetectsWhatItIsMeantTo(): void
    {
        // Témoin positif : sans lui, un motif cassé validerait n'importe quoi.
        $handler = '/\bon(?:click|change)\s*=\s*\\\\?["\']/i';
        $this->assertSame(1, preg_match($handler, '<button onclick="x()">'));
        $this->assertSame(1, preg_match($handler, "<button onchange='x()'>"));
        $this->assertSame(0, preg_match($handler, '<button data-on-click="x">'));

        $this->assertSame(1, preg_match('/[\'"]on[a-z]+[\'"]\s*=>/i', "['onclick' => 'x()']"));
        $this->assertSame(1, preg_match('/(?:href)\s*=\s*\\\\?["\']\s*javascript:/i', '<a href="javascript:x()">'));
    }
}
