<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use Illuminate\Database\Capsule\Manager as Capsule;

final class BackupService
{
    private string $backupDir;
    private string $dbPath;
    private string $uploadsBase;
    private ?Capsule $capsule;

    public function __construct(?Capsule $capsule = null)
    {
        $this->backupDir = storage_path('backups');
        $this->dbPath = storage_path('app/database.sqlite');
        $this->uploadsBase = storage_path('uploads');
        $this->capsule = $capsule;
    }

    public function create(?string $note = null): array
    {
        $this->ensureBackupDir();

        // Précision microseconde : deux sauvegardes créées dans la même
        // seconde (clic manuel rapide, tests) ne doivent pas s'écraser.
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His_u');
        $filename = "backup_{$timestamp}.zip";
        $filepath = $this->backupDir . '/' . $filename;
        $driver = $this->getDriver();

        $zip = new \ZipArchive();
        $res = $zip->open($filepath, \ZipArchive::CREATE);
        if ($res !== true) {
            throw new \RuntimeException("Impossible de créer l'archive de backup (code $res).");
        }

        if ($driver === 'sqlite' && file_exists($this->dbPath)) {
            $zip->addFile($this->dbPath, 'database.sqlite');
        } elseif ($driver === 'mysql') {
            $sql = $this->dumpMysql();
            $zip->addFromString('database.sql', $sql);
        }

        $metadata = [
            'created_at' => date('Y-m-d H:i:s'),
            'note'       => $note ?? '',
            'driver'     => $driver,
        ];
        $zip->addFromString('backup.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (is_dir($this->uploadsBase)) {
            $this->zipDir($zip, $this->uploadsBase, 'uploads');
        }

        $zip->close();

        return [
            'filename'   => $filename,
            'filepath'   => $filepath,
            'size'       => filesize($filepath),
            'created_at' => $metadata['created_at'],
            'note'       => $note ?? '',
        ];
    }

    public function enforceRetention(int $maxKeep): int
    {
        if ($maxKeep <= 0) {
            return 0;
        }
        $files = glob($this->backupDir . '/backup_*.zip');
        if ($files === false || count($files) <= $maxKeep) {
            return 0;
        }
        rsort($files);
        $toDelete = array_slice($files, $maxKeep);
        $deleted = 0;
        foreach ($toDelete as $filepath) {
            if (unlink($filepath)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public function restore(string $filename): bool
    {
        $filepath = $this->getPath($filename);
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Le fichier de backup '$filename' n'existe pas.");
        }

        $this->create('[auto] Backup pré-restauration — ' . $filename);

        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \RuntimeException("Impossible d'ouvrir l'archive '$filename'.");
        }

        $tmpDir = $this->backupDir . '/.restore_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);
        $zip->extractTo($tmpDir);
        $zip->close();

        $meta = $this->readBackupMeta($tmpDir);
        $driver = $meta['driver'] ?? 'sqlite';

        if ($driver === 'sqlite' && file_exists($tmpDir . '/database.sqlite')) {
            copy($tmpDir . '/database.sqlite', $this->dbPath);
        } elseif ($driver === 'mysql' && file_exists($tmpDir . '/database.sql')) {
            $this->restoreMysql($tmpDir . '/database.sql');
        }

        $uploadsTarget = $this->uploadsBase;
        if (is_dir($tmpDir . '/uploads')) {
            $this->recurseCopy($tmpDir . '/uploads', $uploadsTarget);
        }

        $this->recurseRemove($tmpDir);

        return true;
    }

    public function list(): array
    {
        $this->ensureBackupDir();

        $backups = [];
        $files = glob($this->backupDir . '/backup_*.zip');
        if ($files === false || $files === []) {
            return $backups;
        }

        rsort($files);

        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $size = filesize($filepath);
            $mtime = filemtime($filepath);

            $note = '';
            $zip = new \ZipArchive();
            if ($zip->open($filepath) === true) {
                $meta = $zip->getFromName('backup.json');
                if ($meta !== false) {
                    $decoded = json_decode($meta, true);
                    if ($decoded && isset($decoded['created_at'])) {
                        $note = $decoded['note'] ?? '';
                        $mtime = strtotime($decoded['created_at']) ?: $mtime;
                    }
                }
                $zip->close();
            }

            $backups[] = [
                'filename'   => $filename,
                'size'       => $size,
                'created_at' => date('Y-m-d H:i:s', $mtime),
                'note'       => $note,
            ];
        }

        return $backups;
    }

    public function delete(string $filename): bool
    {
        $filepath = $this->getPath($filename);
        if (!file_exists($filepath)) {
            return false;
        }
        return unlink($filepath);
    }

    public function deleteAll(): int
    {
        $this->ensureBackupDir();
        $count = 0;
        foreach (glob($this->backupDir . '/backup_*.zip') ?: [] as $filepath) {
            if (unlink($filepath)) {
                $count++;
            }
        }
        return $count;
    }

    public function getPath(string $filename): string
    {
        return $this->backupDir . '/' . basename($filename);
    }

    /**
     * Zippe l'arborescence de l'application (hors storage/, vendor/ et .git/)
     * comme filet de sécurité manuel avant une mise à jour automatique.
     * vendor/ est exclu du snapshot : composer.lock y est inclus et permet
     * de le reconstruire.
     */
    public function createCodeSnapshot(string $label, ?string $basePath = null): string
    {
        $this->ensureBackupDir();

        $basePath ??= defined('BASE_PATH') ? BASE_PATH : dirname($this->backupDir, 2);
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His_u');
        $safeLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
        $filename = "code_{$safeLabel}_{$timestamp}.zip";
        $filepath = $this->backupDir . '/' . $filename;

        $zip = new \ZipArchive();
        $res = $zip->open($filepath, \ZipArchive::CREATE);
        if ($res !== true) {
            throw new \RuntimeException("Impossible de créer le snapshot de code (code $res).");
        }

        $excluded = ['storage', 'vendor', '.git'];
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            function (\SplFileInfo $current) use ($basePath, $excluded) {
                if (!$current->isDir()) {
                    return true;
                }
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($basePath) + 1));
                return !in_array($relative, $excluded, true);
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', $iterator->getSubPathname());
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($item->getRealPath(), $relative);
            }
        }

        $zip->close();

        return $filepath;
    }

    private function readBackupMeta(string $dir): array
    {
        $path = $dir . '/backup.json';
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getDriver(): string
    {
        try {
            $conn = $this->capsule?->getConnection() ?? Capsule::connection();
            return $conn->getDriverName();
        } catch (\Throwable) {
            return 'sqlite';
        }
    }

    private function conn(): \Illuminate\Database\Connection
    {
        return $this->capsule?->getConnection() ?? Capsule::connection();
    }

    private function getMysqlTableNames(): array
    {
        $conn = $this->conn();
        $rows = $conn->select('SHOW TABLES');
        $key = 'Tables_in_' . $conn->getConfig('database');
        $tables = [];
        foreach ($rows as $row) {
            $rowArr = (array) $row;
            $tables[] = $rowArr[$key] ?? reset($rowArr);
        }
        return $tables;
    }

    private function dumpMysql(): string
    {
        $conn = $this->conn();
        $tables = $this->getMysqlTableNames();

        $sql = '';
        $sql .= "-- Kintai MySQL Dump\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $createSql = $conn->select("SHOW CREATE TABLE `{$table}`");
            $createRow = (array) $createSql[0];
            $createStmt = end($createRow);
            $sql .= $createStmt . ";\n\n";
            $rows = $conn->table($table)->get();
            if ($rows->isNotEmpty()) {
                $sql .= "INSERT INTO `{$table}` VALUES\n";
                $chunks = [];
                foreach ($rows as $row) {
                    $vals = array_map(function ($val) use ($conn) {
                        if ($val === null) return 'NULL';
                        return $conn->getPdo()->quote((string) $val);
                    }, (array) $row);
                    $chunks[] = '(' . implode(',', $vals) . ')';
                }
                $sql .= implode(",\n", $chunks) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $sql;
    }

    private function restoreMysql(string $sqlFile): void
    {
        $pdo = $this->conn()->getPdo();
        $sql = (string) file_get_contents($sqlFile);
        $statements = $this->splitSqlStatements($sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inString = false;
        $stringChar = '';
        $escapeNext = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($escapeNext) {
                $current .= $ch;
                $escapeNext = false;
                continue;
            }
            if ($ch === '\\' && $inString) {
                $current .= $ch;
                $escapeNext = true;
                continue;
            }
            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $current .= $ch;
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ($ch === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $remaining = trim($current);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }

    private function ensureBackupDir(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    private function zipDir(\ZipArchive $zip, string $dir, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $localPath = $zipPrefix . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($item->getRealPath(), $localPath);
            }
        }
    }

    private function recurseCopy(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dst . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getRealPath(), $target);
            }
        }
    }

    private function recurseRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
