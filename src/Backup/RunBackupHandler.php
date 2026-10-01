<?php

namespace App\Backup;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunBackupHandler
{
    public function __construct(
        private readonly SqliteBackup $backup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunBackupMessage $message): void
    {
        if (!$this->backup->isSupported()) {
            $this->logger->info('Sauvegarde planifiée ignorée : la base n\'est pas SQLite (utilisez pg_dump / mysqldump).');

            return;
        }

        $result = $this->backup->create();
        $this->logger->info('Sauvegarde de la base : {path} ({size} octets), {deleted} ancienne(s) supprimée(s).', [
            'path' => $result->path, 'size' => $result->size, 'deleted' => \count($result->deleted),
        ]);
    }
}
