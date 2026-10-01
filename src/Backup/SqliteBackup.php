<?php

namespace App\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sauvegarde cohérente de la base SQLite : VACUUM INTO produit un instantané complet et compact,
 * même pendant que l'application écrit (contrairement à une simple copie de fichier).
 * Une connexion PDO dédiée est utilisée pour ne pas dépendre d'une transaction Doctrine en cours.
 */
final class SqliteBackup
{
    public const FILE_PREFIX = 'webdash-';
    public const FILE_SUFFIX = '.db';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/var/backups')] private readonly string $defaultDirectory,
    ) {
    }

    public function isSupported(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform && '' !== $this->databasePath();
    }

    public function defaultDirectory(): string
    {
        return $this->defaultDirectory;
    }

    /**
     * @throws \RuntimeException si la base n'est pas SQLite, si $keep est invalide ou si la sauvegarde échoue
     */
    public function create(?string $directory = null, int $keep = 14, ?\DateTimeImmutable $now = null): BackupResult
    {
        if (!$this->isSupported()) {
            throw new \RuntimeException('La sauvegarde intégrée ne concerne que SQLite. Pour PostgreSQL ou MySQL, utilisez pg_dump / mysqldump (voir docs/exploitation.md).');
        }
        if ($keep < 1) {
            throw new \RuntimeException('Il faut conserver au moins 1 sauvegarde.');
        }

        $directory ??= $this->defaultDirectory;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Impossible de créer le dossier de sauvegarde "%s".', $directory));
        }

        $target = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.self::FILE_PREFIX.($now ?? new \DateTimeImmutable())->format('Ymd-His').self::FILE_SUFFIX;
        if (file_exists($target)) {
            throw new \RuntimeException(\sprintf('La sauvegarde "%s" existe déjà.', $target));
        }

        try {
            $pdo = new \PDO('sqlite:'.$this->databasePath(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("VACUUM INTO '".str_replace("'", "''", $target)."'");
        } catch (\PDOException $e) {
            throw new \RuntimeException('Sauvegarde impossible : '.$e->getMessage(), 0, $e);
        }

        return new BackupResult($target, (int) filesize($target), $this->rotate($directory, $keep));
    }

    /** Supprime les copies les plus anciennes au-delà de $keep. @return list<string> fichiers supprimés */
    public function rotate(string $directory, int $keep): array
    {
        $files = glob(rtrim($directory, '/\\').\DIRECTORY_SEPARATOR.self::FILE_PREFIX.'*'.self::FILE_SUFFIX) ?: [];
        rsort($files); // le nom contient la date : les plus récentes d'abord

        $deleted = [];
        foreach (\array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $deleted[] = $old;
            }
        }

        return $deleted;
    }

    private function databasePath(): string
    {
        $params = $this->connection->getParams();

        return (string) ($params['path'] ?? '');
    }
}
