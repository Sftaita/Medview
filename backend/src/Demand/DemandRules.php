<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * A line's demand policy as plain values (docs/decisions.md D162) — what
 * DemandTriggerEvaluator reads. No entity, no repository: the same rules
 * can come from the live PlanningLineDemandPolicy or, later, from a copy
 * frozen in a snapshot.
 */
final readonly class DemandRules
{
    /** @var array<string, DemandTriggerRule> keyed by userStableId */
    public array $triggersByUser;

    /**
     * @param list<DemandTriggerRule> $triggers at most one per person
     */
    public function __construct(
        public DemandMode $mode,
        public ?string $sourceLineStableId,
        array $triggers,
        /** docs/decisions.md D224 — conditional only: the line inherits its blocks from its source (never "whole block for one day"). */
        public StructureSource $structureSource = StructureSource::OWN,
        /** docs/decisions.md D233: before this date the line demands nothing; null = no such limit. */
        public ?\DateTimeImmutable $coverageStartsOn = null,
    ) {
        if ($mode->isConditional() !== (null !== $sourceLineStableId)) {
            throw new \InvalidArgumentException('A conditional demand has exactly one source line; an independent one has none.');
        }
        if (!$mode->isConditional() && [] !== $triggers) {
            throw new \InvalidArgumentException('An independent demand has no trigger.');
        }

        $byUser = [];
        foreach ($triggers as $trigger) {
            if (isset($byUser[$trigger->userStableId])) {
                throw new \InvalidArgumentException(\sprintf('Two triggers for the same person "%s".', $trigger->userStableId));
            }
            $byUser[$trigger->userStableId] = $trigger;
        }
        $this->triggersByUser = $byUser;
    }

    /** The same rules with this coverage start. */
    public function withCoverageStart(?\DateTimeImmutable $coverageStartsOn): self
    {
        return new self($this->mode, $this->sourceLineStableId, array_values($this->triggersByUser), $this->structureSource, $coverageStartsOn);
    }

    /** Whether the line demands anything on this calendar day. */
    public function covers(\DateTimeImmutable $localDate): bool
    {
        return self::coverageIncludes($this->coverageStartsOn, $localDate);
    }

    public static function coverageIncludes(?\DateTimeImmutable $coverageStartsOn, \DateTimeImmutable $localDate): bool
    {
        return null === $coverageStartsOn || $localDate->format('Y-m-d') >= $coverageStartsOn->format('Y-m-d');
    }

    public function inheritsStructure(): bool
    {
        return $this->mode->isConditional() && StructureSource::INHERITED === $this->structureSource;
    }

    /** No policy at all means exactly this (D162). */
    public static function independent(): self
    {
        return new self(DemandMode::INDEPENDENT, null, []);
    }
}
