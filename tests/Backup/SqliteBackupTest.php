<?php

namespace App\Tests\Backup;

use App\Backup\RunBackupHandler;
use App\Backup\RunBackupMessage;
use App\Backup\SqliteBackup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class SqliteBackupTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/webdash-backup-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function backup(): SqliteBackup
    {
        return self::getContainer()->get(SqliteBackup::class);
    }

    public function testBackupIsACompleteCopyOfTheDatabase(): void
    {
        $result = $this->backup()->create($this->dir);

        $this->assertFileExists($result->path);
        $this->assertGreaterThan(0, $result->size);
        $this->assertMatchesRegularExpression('#webdash-\d{8}-\d{6}\.db$#', $result->path);

        // la copie s'ouvre comme une vraie base et contient les mêmes données que l'originale
        $copy = new \PDO('sqlite:'.$result->path);
        $this->assertSame('ok', $copy->query('PRAGMA integrity_check')->fetchColumn());
        $live = self::getContainer()->get(Connection::class);
        foreach (['user', 'domain', 'kpi', 'membership'] as $table) {
            $this->assertSame(
                (int) $live->fetchOne("SELECT COUNT(*) FROM \"$table\""),
                (int) $copy->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn(),
                "table $table",
            );
        }
        $this->assertGreaterThan(0, (int) $copy->query('SELECT COUNT(*) FROM "user"')->fetchColumn());
    }

    public function testRotationKeepsOnlyTheMostRecentCopies(): void
    {
        $backup = $this->backup();
        foreach (['2026-09-01 02:00', '2026-09-02 02:00', '2026-09-03 02:00', '2026-09-04 02:00'] as $date) {
            $backup->create($this->dir, 3, new \DateTimeImmutable($date));
        }

        $files = array_map('basename', glob($this->dir.'/*.db'));
        sort($files);
        $this->assertSame(['webdash-20260902-020000.db', 'webdash-20260903-020000.db', 'webdash-20260904-020000.db'], $files);
    }

    public function testRotationIgnoresOtherFiles(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir.'/notes.txt', 'à garder');
        file_put_contents($this->dir.'/autre.db', 'pas une sauvegarde WebDash');

        $this->backup()->create($this->dir, 1, new \DateTimeImmutable('2026-09-01 02:00'));
        $this->backup()->create($this->dir, 1, new \DateTimeImmutable('2026-09-02 02:00'));

        $this->assertFileExists($this->dir.'/notes.txt');
        $this->assertFileExists($this->dir.'/autre.db');
        $this->assertCount(1, glob($this->dir.'/webdash-*.db'));
    }

    public function testInvalidRetentionAndExistingTargetAreRefused(): void
    {
        try {
            $this->backup()->create($this->dir, 0);
            $this->fail('keep=0 doit être refusé');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('au moins 1', $e->getMessage());
        }

        $now = new \DateTimeImmutable('2026-09-01 02:00');
        $this->backup()->create($this->dir, 5, $now);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('existe déjà');
        $this->backup()->create($this->dir, 5, $now);
    }

    public function testCommand(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:backup'));
        $tester->execute(['--dir' => $this->dir, '--keep' => 5]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Sauvegarde créée', $tester->getDisplay());
        $this->assertCount(1, glob($this->dir.'/webdash-*.db'));

        $tester->execute(['--dir' => $this->dir, '--keep' => 0]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('au moins 1', $tester->getDisplay());
    }

    public function testScheduledHandlerBacksUpToTheDefaultDirectory(): void
    {
        $default = $this->backup()->defaultDirectory();
        $before = glob($default.'/webdash-*.db') ?: [];

        (self::getContainer()->get(RunBackupHandler::class))(new RunBackupMessage());

        $after = glob($default.'/webdash-*.db') ?: [];
        $created = array_diff($after, $before);
        $this->assertCount(1, $created, 'une sauvegarde créée par la tâche planifiée');
        foreach ($created as $file) {
            @unlink($file); // ne pas laisser de copie de test dans var/backups
        }
    }
}
