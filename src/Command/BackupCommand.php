<?php

namespace App\Command;

use App\Backup\SqliteBackup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:backup', description: 'Sauvegarde la base SQLite (copie cohérente, avec rotation des anciennes copies)')]
final class BackupCommand
{
    public function __construct(private readonly SqliteBackup $backup)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Dossier de destination (défaut : var/backups)')] ?string $dir = null,
        #[Option('Nombre de sauvegardes à conserver')] int $keep = 14,
    ): int {
        try {
            $result = $this->backup->create($dir, $keep);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Sauvegarde créée : %s (%s ko).', $result->path, number_format($result->size / 1024, 0, ',', ' ')));
        if ([] !== $result->deleted) {
            $io->note(\sprintf('%d ancienne(s) sauvegarde(s) supprimée(s) (on garde les %d plus récentes).', \count($result->deleted), $keep));
        }

        return Command::SUCCESS;
    }
}
