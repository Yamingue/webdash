<?php

namespace App\Backup;

final readonly class BackupResult
{
    /** @param list<string> $deleted copies supprimées par la rotation */
    public function __construct(
        public string $path,
        public int $size,
        public array $deleted,
    ) {
    }
}
