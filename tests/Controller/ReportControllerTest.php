<?php

namespace App\Tests\Controller;

use App\Entity\Evaluation;
use App\Enum\EvaluationStatus;
use App\Repository\DomainRepository;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ReportControllerTest extends AppWebTestCase
{
    private const PERIOD = '?from=2026-09-01&to=2026-09-28';

    private function seed(): void
    {
        $container = static::getContainer();
        $domains = $container->get(DomainRepository::class);
        $em = $container->get(EntityManagerInterface::class);

        foreach ([['reseau-arcep', '2026-09-14', 95, EvaluationStatus::Submitted], ['finance', '2026-09-14', 100, EvaluationStatus::Submitted], ['reseau-arcep', '2026-09-21', 12, EvaluationStatus::Draft]] as [$slug, $week, $score, $status]) {
            $evaluation = (new Evaluation($domains->findOneBy(['slug' => $slug])->getKpis()->first(), new \DateTimeImmutable($week)))->setScore($score);
            if (EvaluationStatus::Draft !== $status) {
                $evaluation->submit();
            }
            $em->persist($evaluation);
        }
        $em->flush();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/rapports');
        $this->assertResponseRedirects('/login');

        $client->request('GET', '/rapports/export.csv');
        $this->assertResponseRedirects('/login');
    }

    public function testPageShowsOnlyAccessibleDomains(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->seed();

        $crawler = $client->request('GET', '/rapports'.self::PERIOD);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Réseau & ARCEP');
        $this->assertSelectorTextNotContains('main', 'Finance');
        $this->assertSame(['', (string) $this->domainId('reseau-arcep')], $crawler->filter('select[name="domain"] option')->extract(['value']));
        $this->assertSelectorTextContains('main', 'Détail (1 ligne)'); // brouillon exclu par défaut
    }

    public function testAdminSeesEverythingAndFiltersWork(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seed();

        $client->request('GET', '/rapports'.self::PERIOD);
        $this->assertSelectorTextContains('main', 'Détail (2 lignes)');

        $client->request('GET', '/rapports'.self::PERIOD.'&status=all');
        $this->assertSelectorTextContains('main', 'Détail (3 lignes)');

        $client->request('GET', '/rapports'.self::PERIOD.'&domain='.$this->domainId('finance'));
        $this->assertSelectorTextContains('main', 'Détail (1 ligne)');

        $client->request('GET', '/rapports'.self::PERIOD.'&status=validated');
        $this->assertSelectorTextContains('main', 'Aucune évaluation sur cette période');
    }

    public function testFilterOnUnreachableDomainIsForbidden(): void
    {
        $client = $this->clientFor('manager@example.com');
        $finance = $this->domainId('finance');

        $client->request('GET', '/rapports?domain='.$finance);
        $this->assertResponseStatusCodeSame(403);
        $client->request('GET', '/rapports/export.csv?domain='.$finance);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $client = $this->clientFor('admin@example.com');
        $client->request('GET', '/rapports?status=nimporte');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCsvExportIsScopedAndWellFormed(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->seed();

        $client->request('GET', '/rapports/export.csv'.self::PERIOD);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=rapport-20260831-20260928.csv', $client->getResponse()->headers->get('Content-Disposition'));

        $csv = $client->getInternalResponse()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDomaine;\"Code KPI\";KPI;", $csv);
        $this->assertStringContainsString('"Réseau & ARCEP";SCAT1;', $csv);
        $this->assertStringNotContainsString('Finance', $csv, 'pas de données hors périmètre');
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'en-tête + 1 ligne (brouillon exclu)');
        $this->assertStringContainsString(';95;95;100;', $csv, 'objectif;score;atteinte');
    }

    public function testCsvIncludesEveryRowForAdminAndDraftsOnRequest(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seed();

        $client->request('GET', '/rapports/export.csv'.self::PERIOD.'&status=all');
        $this->assertSame(4, substr_count(trim($client->getInternalResponse()->getContent()), "\n") + 1);
    }

    public function testExportLinkKeepsTheFilters(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/rapports'.self::PERIOD.'&status=validated');

        $href = $crawler->selectLink('Exporter en CSV')->attr('href');
        $this->assertStringContainsString('from=2026-08-31', $href);
        $this->assertStringContainsString('to=2026-09-28', $href);
        $this->assertStringContainsString('status=validated', $href);
    }

    private function domainId(string $slug): int
    {
        return static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug])->getId();
    }
}
