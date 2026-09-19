<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PlanningRuleSetConfiguration;
use App\Entity\PlanningRuleSet;
use App\Entity\PlanningTeam;
use App\Entity\User;
use App\Repository\PlanningRuleSetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Owns PlanningRuleSet versioning (docs/decisions.md D039): version
 * numbers are assigned here (never left to the caller, avoiding a race on
 * "what's the next version"), and activation always retires whichever
 * RuleSet was previously ACTIVE for the Team so at most one is ever in
 * force at a time.
 */
final class PlanningRuleSetService
{
    public function __construct(
        private readonly PlanningRuleSetRepository $repository,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ValidationFailedException
     */
    public function createDraft(
        PlanningTeam $team,
        \DateTimeImmutable $effectiveFrom,
        PlanningRuleSetConfiguration $configuration,
        ?User $createdBy = null,
    ): PlanningRuleSet {
        $this->validate($configuration);

        $ruleSet = new PlanningRuleSet(
            $team,
            $this->repository->findNextVersionNumber($team),
            $effectiveFrom,
            $configuration->toArray(),
            $createdBy,
        );

        $this->entityManager->persist($ruleSet);
        $this->entityManager->flush();

        return $ruleSet;
    }

    /**
     * @throws ValidationFailedException
     * @throws \App\Exception\ImmutableRuleSetException if $ruleSet is no longer DRAFT
     */
    public function updateDraft(PlanningRuleSet $ruleSet, PlanningRuleSetConfiguration $configuration): void
    {
        $this->validate($configuration);
        $ruleSet->updateConfiguration($configuration->toArray());
        $this->entityManager->flush();
    }

    /**
     * Retires the Team's currently ACTIVE RuleSet (if any) and activates
     * this one instead, atomically.
     *
     * @throws \App\Exception\ImmutableRuleSetException if $ruleSet is no longer DRAFT
     */
    public function activate(PlanningRuleSet $ruleSet): void
    {
        // Two separate flushes, deliberately: "at most one ACTIVE per team"
        // is a plain partial unique index (see migrations), which — unlike
        // a deferrable EXCLUDE constraint — Postgres always checks
        // immediately per statement. Retiring the old one and activating
        // the new one in the same flush() risks both UPDATEs landing with
        // status=ACTIVE simultaneously for one instant, tripping the index
        // depending on statement order.
        $currentlyActive = $this->repository->findActive($ruleSet->getTeam());
        if (null !== $currentlyActive) {
            $currentlyActive->retire();
            $this->entityManager->flush();
        }

        $ruleSet->activate();
        $this->entityManager->flush();
    }

    private function validate(PlanningRuleSetConfiguration $configuration): void
    {
        $violations = $this->validator->validate($configuration);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($configuration, $violations);
        }
    }
}
