<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Écrit une variable dans le fichier d'environnement de l'instance, celui que kintai_load_env_file() charge
 * (.env.local, sinon .env.ovh, sinon .env ; .env est créé s'il n'y en a aucun). La ligne existante est remplacée,
 * les autres lignes (commentaires compris) restent telles quelles, et la valeur prend effet dans la requête en cours.
 */
final class EnvFileWriter
{
    private const FILES = ['.env.local', '.env.ovh', '.env'];

    public function __construct(private readonly string $basePath = BASE_PATH) {}

    /** Fichier qui reçoit l'écriture : le premier existant de l'ordre de chargement, sinon .env. */
    public function path(): string
    {
        foreach (self::FILES as $name) {
            if (file_exists($this->basePath . '/' . $name)) {
                return $this->basePath . '/' . $name;
            }
        }
        return $this->basePath . '/.env';
    }

    /**
     * @throws \InvalidArgumentException nom ou valeur non représentable sur une ligne
     * @throws \RuntimeException         fichier non inscriptible
     */
    public function set(string $key, string $value): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || preg_match('/[\r\n"]/', $value) === 1) {
            throw new \InvalidArgumentException("Variable d'environnement invalide : {$key}");
        }

        $path = $this->path();
        $content = file_exists($path) ? (string) file_get_contents($path) : '';
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $line = $key . '=' . (preg_match('/[\s#]/', $value) === 1 ? '"' . $value . '"' : $value);

        $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[^\r\n]*/m';
        if (preg_match($pattern, $content) === 1) {
            $content = (string) preg_replace($pattern, $line, $content, 1);
        } else {
            $content = ($content === '' || str_ends_with($content, "\n") ? $content : $content . $eol) . $line . $eol;
        }

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Fichier non inscriptible : ' . basename($path));
        }

        putenv($key . '=' . $value);
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}
