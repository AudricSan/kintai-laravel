<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Installé dans un sous-dossier (https://hote/Kintai/), l'application doit rester sous ce préfixe. Une redirection
 * écrite `Response::redirect('/admin/...')` renvoie à la racine du domaine : 404. Le préfixe vient de
 * `$this->base()` (trait HasBaseUrl) ou de `base_url()`.
 */
final class RedirectsKeepBasePathTest extends TestCase
{
    public function testNoControllerRedirectsToAnAbsolutePathWithoutTheBasePrefix(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match("/Response::redirect\(\s*['\"]\//", $line) === 1) {
                    $offenders[] = str_replace(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "Redirections sans préfixe du sous-dossier :\n" . implode("\n", $offenders));
    }
}
