<?php

namespace App\Tests\Service;

use App\Service\SafeFlusher;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\TestCase;

final class SafeFlusherTest extends TestCase
{
    public function testReturnsTrueWhenEverythingIsSaved(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $this->assertTrue((new SafeFlusher($em))->flush());
    }

    public function testOptimisticLockConflictReturnsFalse(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(OptimisticLockException::lockFailed(new \stdClass()));

        $this->assertFalse((new SafeFlusher($em))->flush());
    }

    public function testUniqueConstraintViolationReturnsFalse(): void
    {
        $driver = new class('duplicate') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return '23000';
            }
        };
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new UniqueConstraintViolationException($driver, null));

        $this->assertFalse((new SafeFlusher($em))->flush());
    }

    public function testOtherErrorsAreNotSwallowed(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('disque plein'));

        $this->expectException(\RuntimeException::class);
        (new SafeFlusher($em))->flush();
    }
}
