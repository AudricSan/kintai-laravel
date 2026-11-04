<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Jeu de données construit sur une seule requête, déjà bornée par le périmètre, triée et stable.
 */
abstract class AbstractExportDataset implements ExportDataset
{
    private const CHUNK = 500;

    public function selfService(): bool
    {
        return false;
    }

    public function perEmployee(): bool
    {
        return true;
    }

    public function hasPeriod(): bool
    {
        return false;
    }

    public function formats(): array
    {
        return [ExportWriter::CSV, ExportWriter::XLSX, ExportWriter::PDF];
    }

    /**
     * @return EloquentBuilder<*>|QueryBuilder
     */
    abstract protected function query(ExportScope $scope): EloquentBuilder|QueryBuilder;

    /**
     * @return array<string, scalar|null>
     */
    abstract protected function row(mixed $record): array;

    public function count(ExportScope $scope): int
    {
        return $this->query($scope)->count();
    }

    public function rows(ExportScope $scope): iterable
    {
        foreach ($this->query($scope)->lazy(self::CHUNK) as $record) {
            yield $this->row($record);
        }
    }
}
