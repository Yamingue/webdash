<?php

namespace App\Tests\Entity;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluationTest extends TestCase
{
    private function evaluation(?float $score = 80.0): Evaluation
    {
        $kpi = (new Kpi())->setDefaultTarget(100);
        (new Domain())->addKpi($kpi);

        return (new Evaluation($kpi, new \DateTimeImmutable('2026-09-30')))->setScore($score);
    }

    /** @return iterable<string, array{bool, float, ?float, ?float}> plus bas = mieux ?, objectif, score, atteinte attendue */
    public static function achievements(): iterable
    {
        yield 'haut : sous l\'objectif' => [false, 100, 95, 95.0];
        yield 'haut : à l\'objectif' => [false, 100, 100, 100.0];
        yield 'haut : dépassé' => [false, 100, 123, 123.0];
        yield 'haut : objectif 0 sans objet' => [false, 0, 5, null];
        yield 'bas : à l\'objectif' => [true, 100, 100, 100.0];
        yield 'bas : 10 % au-dessus = 90 %' => [true, 100, 110, 90.0];
        yield 'bas : 10 % en dessous = 110 %' => [true, 100, 90, 110.0];
        yield 'bas : le double = 0 %' => [true, 100, 200, 0.0];
        yield 'bas : bien au-delà, plancher 0 %' => [true, 100, 350, 0.0];
        yield 'bas : score 0 = 200 %' => [true, 100, 0, 200.0];
        yield 'bas : objectif 0, score 0 = 100 %' => [true, 0, 0, 100.0];
        yield 'bas : objectif 0, score > 0 = 0 %' => [true, 0, 4, 0.0];
        yield 'sans score' => [false, 100, null, null];
        yield 'bas sans score' => [true, 100, null, null];
    }

    #[DataProvider('achievements')]
    public function testAchievementDependsOnDirection(bool $lowerIsBetter, float $target, ?float $score, ?float $expected): void
    {
        $kpi = (new Kpi())->setDefaultTarget($target)->setLowerIsBetter($lowerIsBetter);
        (new Domain())->addKpi($kpi);
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable('2026-09-30')))->setScore($score);

        $this->assertSame($expected, $evaluation->getAchievement());
    }

    public function testDirectionAndWeightAreFrozenAtCreation(): void
    {
        $kpi = (new Kpi())->setDefaultTarget(100)->setWeight(3)->setLowerIsBetter(true);
        (new Domain())->addKpi($kpi);
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable('2026-09-30')))->setScore(110);

        // le KPI change ensuite : l'évaluation déjà créée ne bouge pas
        $kpi->setLowerIsBetter(false)->setWeight(10);

        $this->assertTrue($evaluation->isLowerIsBetter());
        $this->assertSame(3.0, $evaluation->getWeight());
        $this->assertSame(90.0, $evaluation->getAchievement());

        $next = new Evaluation($kpi, new \DateTimeImmutable('2026-10-07'));
        $this->assertFalse($next->isLowerIsBetter());
        $this->assertSame(10.0, $next->getWeight());
    }

    public function testReopenOnlyFromValidatedAndClearsValidation(): void
    {
        $evaluation = $this->evaluation();
        $evaluation->submit()->validate(new User());

        $evaluation->reopen('Erreur de saisie');

        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus());
        $this->assertSame('Erreur de saisie', $evaluation->getRejectionReason());
        $this->assertNull($evaluation->getValidatedBy());
        $this->assertNull($evaluation->getValidatedAt());
        $this->assertNull($evaluation->getSubmittedAt());
        $this->assertTrue($evaluation->isEditable());

        $this->expectException(\LogicException::class);
        $this->evaluation()->reopen('pas validée');
    }

    public function testLifecycleSubmitThenValidate(): void
    {
        $evaluation = $this->evaluation();
        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus());
        $this->assertTrue($evaluation->isEditable());
        $this->assertSame('2026-09-28', $evaluation->getWeekStart()->format('Y-m-d'));

        $evaluation->submit();
        $this->assertSame(EvaluationStatus::Submitted, $evaluation->getStatus());
        $this->assertFalse($evaluation->isEditable());
        $this->assertNotNull($evaluation->getSubmittedAt());

        $manager = new User();
        $evaluation->validate($manager);
        $this->assertSame(EvaluationStatus::Validated, $evaluation->getStatus());
        $this->assertSame($manager, $evaluation->getValidatedBy());
        $this->assertNotNull($evaluation->getValidatedAt());
    }

    public function testRejectSendsBackToDraftWithReasonAndResubmitClearsIt(): void
    {
        $evaluation = $this->evaluation();
        $evaluation->submit()->reject('Chiffre incohérent');

        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus());
        $this->assertSame('Chiffre incohérent', $evaluation->getRejectionReason());
        $this->assertTrue($evaluation->isEditable());

        $evaluation->submit();
        $this->assertNull($evaluation->getRejectionReason());
    }

    public function testCannotSubmitWithoutScore(): void
    {
        $this->expectException(\LogicException::class);
        $this->evaluation(null)->submit();
    }

    public function testCannotValidateOrRejectADraft(): void
    {
        $this->expectException(\LogicException::class);
        $this->evaluation()->validate(new User());
    }

    public function testCannotRejectADraft(): void
    {
        $this->expectException(\LogicException::class);
        $this->evaluation()->reject('non');
    }

    public function testValidatedEvaluationIsFinal(): void
    {
        $evaluation = $this->evaluation();
        $evaluation->submit()->validate(new User());

        $this->expectException(\LogicException::class);
        $evaluation->reject('trop tard');
    }
}
