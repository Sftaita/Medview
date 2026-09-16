<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\PlanningRuleSetConfiguration;
use App\Entity\PlanningRuleSetStatus;
use App\Exception\ImmutableRuleSetException;
use App\Service\PlanningRuleSetService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class PlanningRuleSetServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    private function validConfig(): PlanningRuleSetConfiguration
    {
        $config = new PlanningRuleSetConfiguration();
        $config->maxDutiesPerFairnessPeriod = 40;
        $config->teamMinRestHours = 11;
        $config->holidayDecayFactor = 0.5;

        return $config;
    }

    public function testFirstDraftGetsVersionOne(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $ruleSet = $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());

        self::assertSame(1, $ruleSet->getVersion());
        self::assertSame(PlanningRuleSetStatus::DRAFT, $ruleSet->getStatus());
    }

    public function testVersionsIncrementSequentiallyPerTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());
        $second = $service->createDraft($team, $this->date('2028-01-01'), $this->validConfig());

        self::assertSame(2, $second->getVersion());
    }

    public function testEachTeamHasItsOwnVersionSequence(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');

        $service->createDraft($teamA, $this->date('2027-01-01'), $this->validConfig());
        $firstForB = $service->createDraft($teamB, $this->date('2027-01-01'), $this->validConfig());

        self::assertSame(1, $firstForB->getVersion());
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $config = $this->validConfig();
        $config->maxDutiesPerFairnessPeriod = -5;

        $this->expectException(ValidationFailedException::class);
        $service->createDraft($team, $this->date('2027-01-01'), $config);
    }

    public function testActivatingRetiresThePreviousActiveRuleSet(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $first = $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());
        $service->activate($first);

        $second = $service->createDraft($team, $this->date('2028-01-01'), $this->validConfig());
        $service->activate($second);

        self::assertSame(PlanningRuleSetStatus::RETIRED, $first->getStatus());
        self::assertSame(PlanningRuleSetStatus::ACTIVE, $second->getStatus());
    }

    public function testActiveRuleSetCanNeverBeModifiedAgain(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $ruleSet = $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());
        $service->activate($ruleSet);

        $this->expectException(ImmutableRuleSetException::class);
        $service->updateDraft($ruleSet, $this->validConfig());
    }

    public function testRetiredRuleSetCanNeverBeModifiedAgain(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $first = $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());
        $service->activate($first);
        $service->activate($service->createDraft($team, $this->date('2028-01-01'), $this->validConfig()));

        self::assertSame(PlanningRuleSetStatus::RETIRED, $first->getStatus());

        $this->expectException(ImmutableRuleSetException::class);
        $service->updateDraft($first, $this->validConfig());
    }

    public function testDraftCanBeUpdatedFreely(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $ruleSet = $service->createDraft($team, $this->date('2027-01-01'), $this->validConfig());

        $updated = $this->validConfig();
        $updated->maxDutiesPerFairnessPeriod = 50;
        $service->updateDraft($ruleSet, $updated);

        self::assertSame(50, $ruleSet->getConfiguration()['maxDutiesPerFairnessPeriod']);
    }
}
