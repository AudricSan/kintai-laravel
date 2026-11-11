<?php

namespace App\Install;

/**
 * Lecture et écriture ciblées du fichier `.env` (comme `key:generate`) : une valeur à la fois, le reste du fichier
 * (commentaires, ordre) reste intact.
 */
final class EnvironmentFile
{
    public function __construct(private readonly string $path, private readonly string $examplePath) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Copie `.env.example` si `.env` n'existe pas. Ne remplace jamais un `.env` existant.
     *
     * @return bool vrai si le fichier vient d'être créé
     */
    public function createFromExample(): bool
    {
        if ($this->exists()) {
            return false;
        }

        if (! is_file($this->examplePath)) {
            throw new InstallFailure(__('The .env.example file is missing.'));
        }

        if (@copy($this->examplePath, $this->path) === false) {
            throw new InstallFailure(__('The .env file could not be created. Check the folder permissions.'));
        }

        return true;
    }

    /**
     * Valeur d'une variable active (non commentée), sans guillemets ; null si absente.
     */
    public function get(string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', $this->read(), $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * Fixe des variables : remplace la ligne active, sinon la ligne commentée de l'exemple, sinon ajoute en fin de fichier.
     *
     * @param  array<string, string>  $values
     */
    public function set(array $values): void
    {
        $contents = $this->read();

        foreach ($values as $name => $value) {
            $line = $name.'='.$this->format($value);
            $quoted = preg_quote($name, '/');

            foreach (['/^'.$quoted.'=.*$/m', '/^#\s*'.$quoted.'=.*$/m'] as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $contents = (string) preg_replace_callback($pattern, fn (): string => $line, $contents, 1);

                    continue 2;
                }
            }

            $contents = rtrim($contents, "\r\n").($contents === '' ? '' : "\n").$line."\n";
        }

        $this->write($contents);
    }

    /**
     * Retire les variables actives données.
     *
     * @param  list<string>  $names
     */
    public function forget(array $names): void
    {
        $contents = $this->read();

        foreach ($names as $name) {
            $contents = (string) preg_replace('/^'.preg_quote($name, '/').'=.*\R?/m', '', $contents);
        }

        $this->write($contents);
    }

    private function read(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function write(string $contents): void
    {
        if (@file_put_contents($this->path, $contents) === false) {
            throw new InstallFailure(__('The .env file could not be written. Check its permissions.'));
        }
    }

    private function format(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/:@+,=-]*$/', $value) === 1) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }
}
