<?php

namespace App\Service;

/** Les évaluations sont hebdomadaires : une semaine est identifiée par son lundi. */
final class Week
{
    public static function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0);
    }

    /** Lundi de la semaine contenant la date demandée (Y-m-d), sinon de la semaine courante. Passé et futur autorisés. */
    public static function resolve(?string $requested, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $current = self::mondayOf($now ?? new \DateTimeImmutable());
        $date = $requested ? \DateTimeImmutable::createFromFormat('!Y-m-d', $requested) : false;
        if (false === $date) {
            return $current;
        }

        return self::mondayOf($date);
    }

    /** @return list<\DateTimeImmutable> les $count derniers lundis, du plus ancien au plus récent */
    public static function lastWeeks(int $count, ?\DateTimeImmutable $now = null): array
    {
        $current = self::mondayOf($now ?? new \DateTimeImmutable());
        $weeks = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $weeks[] = $current->modify("-{$i} weeks");
        }

        return $weeks;
    }
}
