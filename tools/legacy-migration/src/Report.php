<?php

declare(strict_types=1);

namespace KintaiLegacy;

/** Comptes par table et avertissements d'une exécution. */
final class Report
{
    /** @var array<string, array{lues: int, importees: int, ignorees: int}> */
    public array $tables = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $notes = [];

    public bool $dryRun = false;

    public function read(string $table): void
    {
        $this->ensure($table);
        $this->tables[$table]['lues']++;
    }

    public function imported(string $table): void
    {
        $this->ensure($table);
        $this->tables[$table]['importees']++;
    }

    public function skipped(string $table, string $reason): void
    {
        $this->ensure($table);
        $this->tables[$table]['ignorees']++;
        $this->warn($table.' : '.$reason);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function count(string $table, string $field = 'importees'): int
    {
        return $this->tables[$table][$field] ?? 0;
    }

    public function render(): string
    {
        $lines = [$this->dryRun ? "Rapport (simulation : rien n'a été écrit)" : 'Rapport de migration', ''];
        $lines[] = str_pad('Table cible', 24).str_pad('lues', 8).str_pad('importées', 11).'ignorées';
        foreach ($this->tables as $table => $counts) {
            $lines[] = str_pad($table, 24).str_pad((string) $counts['lues'], 8).str_pad((string) $counts['importees'], 11).$counts['ignorees'];
        }
        if ($this->notes !== []) {
            $lines[] = '';
            foreach ($this->notes as $note) {
                $lines[] = 'Note : '.$note;
            }
        }
        $lines[] = '';
        $lines[] = count($this->warnings).' avertissement(s)';
        foreach ($this->warnings as $warning) {
            $lines[] = ' - '.$warning;
        }

        return implode("\n", $lines)."\n";
    }

    private function ensure(string $table): void
    {
        $this->tables[$table] ??= ['lues' => 0, 'importees' => 0, 'ignorees' => 0];
    }
}
