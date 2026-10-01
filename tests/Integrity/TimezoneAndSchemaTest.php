<?php

namespace App\Tests\Integrity;

use App\Kernel;
use App\Service\Week;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimezoneAndSchemaTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['APP_TIMEZONE']);
        parent::tearDown();
    }

    public function testApplicationRunsInNdjamenaByDefault(): void
    {
        self::bootKernel();

        $this->assertSame(Kernel::DEFAULT_TIMEZONE, date_default_timezone_get());
        $this->assertSame('Africa/Ndjamena', date_default_timezone_get());
    }

    public function testTimezoneCanBeOverriddenWithAppTimezone(): void
    {
        $_SERVER['APP_TIMEZONE'] = 'Europe/Paris';
        self::bootKernel();

        $this->assertSame('Europe/Paris', date_default_timezone_get());
    }

    public function testInvalidTimezoneFallsBackToTheDefault(): void
    {
        $_SERVER['APP_TIMEZONE'] = 'Mars/Olympus_Mons';
        self::bootKernel();

        $this->assertSame(Kernel::DEFAULT_TIMEZONE, date_default_timezone_get());
    }

    public function testCurrentWeekFollowsTheApplicationTimezone(): void
    {
        self::bootKernel();

        // Lundi 00h30 à N'Djamena = dimanche soir à Paris/UTC : la semaine en cours est bien celle du lundi local.
        $localMondayNight = new \DateTimeImmutable('2026-09-28 00:30', new \DateTimeZone('Africa/Ndjamena'));
        $this->assertSame('2026-09-28', Week::mondayOf($localMondayNight)->format('Y-m-d'));
        $this->assertSame('2026-09-28', Week::resolve(null, $localMondayNight)->format('Y-m-d'));
    }

    public function testEvaluationTableHasIndexesOnWeekAndStatus(): void
    {
        self::bootKernel();
        $table = self::getContainer()->get(Connection::class)->createSchemaManager()->introspectTable('evaluation');

        $columns = [];
        foreach ($table->getIndexes() as $index) {
            array_push($columns, ...$index->getColumns());
        }

        $this->assertContains('week_start', $columns);
        $this->assertContains('status', $columns);
        $this->assertTrue($table->hasColumn('version'));
    }
}
