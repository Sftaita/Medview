<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\WeekStructureUpdateRequest;
use App\Entity\AllocationFamily;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeam;
use App\Exception\InvalidWeekStructureException;
use App\Repository\AllocationFamilyRepository;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and replaces a PlanningLine's weekly structure (docs/decisions.md
 * D136, docs/week-structure.md) — the persistence side of the
 * `WeekStructureEditor` frontend component. A "weekly structure" is
 * nothing more than the team's own set of active, `recurring = true`
 * `DutyPattern`s (`DutyPatternRepository::findActiveRecurringByTeam()`,
 * never `findActiveByTeam()` — see that field's own docblock): no second,
 * competing representation is stored anywhere (§0 of the spec: reuse
 * DutyPattern/DutyPatternComponent, never invent a parallel concept).
 *
 * `dayOffset` is reinterpreted here as a plain ISO weekday index (0=Monday
 * .. 6=Sunday), the same convention `WeekStructureEditor`'s `DAY_CODES`
 * already uses — `DutyPatternComponent` itself needed no change:
 * `docs/planning-domain.md §9` already allows an arbitrary, non-contiguous
 * offset per component. `WeeklyDutyCalendarService` later re-anchors each
 * active recurring pattern on the Monday of every week it materializes.
 *
 * Every `replace()` is a **full, atomic replacement** (§14 of the spec):
 * the team's currently-active recurring patterns are deactivated — never mutated,
 * never deleted (docs/planning-domain.md §14: "RESTRICT empêche la
 * suppression une fois référencée") — and entirely fresh patterns are
 * created for the submitted structure. This is what makes history
 * automatically immune to a later edit (§12 of the spec): a Duty already
 * materialized keeps pointing at its own (now possibly inactive, never
 * mutated) DutyPattern and AllocationFamily forever; only patterns
 * materialized *after* an edit ever see the new structure
 * (docs/week-structure.md §4: "une modification ne s'applique qu'aux
 * prochaines générations").
 */
final class WeekStructureService
{
    private const DAY_CODES = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
    private const DEFAULT_DUTY_TYPE_CODE = 'GARDE';

    public function __construct(
        private readonly DutyPatternRepository $patternRepository,
        private readonly DutyTypeRepository $dutyTypeRepository,
        private readonly AllocationFamilyRepository $familyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function read(PlanningLine $line): WeekStructureView
    {
        $team = $line->getPlanningTeam();
        $patterns = $this->patternRepository->findActiveRecurringByTeam($team);

        $blocks = [];
        $solo = [];
        $classifiedDays = [];

        foreach ($patterns as $pattern) {
            $days = $this->patternDayCodes($pattern);
            foreach ($days as $day) {
                $classifiedDays[$day] = true;
            }

            $familyName = $pattern->getFamily()?->getName();

            if (\count($days) >= 2) {
                $blocks[] = new WeekStructureBlockView($pattern->getName(), $days, $familyName);
            } else {
                $solo[] = $days[0];
                // Every solo pattern of a line shares the same $soloFamily
                // by construction of replace() below — the first one
                // encountered decides it, consistent for the whole line.
                $soloFamily ??= $familyName;
            }
        }

        $excluded = array_values(array_diff(self::DAY_CODES, array_keys($classifiedDays)));

        // Preserve DAY_CODES order for solo/excluded regardless of the
        // (unordered) order patterns came back from the repository.
        $solo = array_values(array_intersect(self::DAY_CODES, $solo));

        return new WeekStructureView($blocks, $solo, $soloFamily ?? null, $excluded);
    }

    /**
     * @throws InvalidWeekStructureException
     */
    public function replace(PlanningLine $line, WeekStructureUpdateRequest $request): WeekStructureView
    {
        $team = $line->getPlanningTeam();
        $this->validate($request);

        return $this->entityManager->wrapInTransaction(function () use ($team, $request, $line): WeekStructureView {
            foreach ($this->patternRepository->findActiveRecurringByTeam($team) as $existing) {
                $existing->setActive(false);
            }

            $dutyType = $this->resolveDefaultDutyType($team);

            foreach ($request->blocks as $block) {
                $family = $this->resolveFamily($team, $block->family);
                $pattern = new DutyPattern($team, $this->freshCode('BLK'), $block->name, $family, recurring: true);
                $this->entityManager->persist($pattern);
                foreach ($block->days as $day) {
                    $pattern->addComponent($this->dayOffset($day), $dutyType);
                }
            }

            $soloFamily = $this->resolveFamily($team, $request->soloFamily);
            foreach ($request->solo as $day) {
                $pattern = new DutyPattern($team, $this->freshCode('SOLO'), self::dayName($day), $soloFamily, recurring: true);
                $this->entityManager->persist($pattern);
                $pattern->addComponent($this->dayOffset($day), $dutyType);
            }

            $this->entityManager->flush();

            return $this->read($line);
        });
    }

    /**
     * @throws InvalidWeekStructureException
     */
    private function validate(WeekStructureUpdateRequest $request): void
    {
        // No explicit "at most 4 blocks" check: the day-uniqueness check
        // below already makes a 4th block structurally unreachable — 4
        // blocks would need at least 8 distinct days (each block requires
        // ≥ 2), but a week has only 7. WeekStructureController's letter
        // assignment (A-D) never actually needs a 5th letter as a result;
        // adding a redundant count check here would be unreachable dead
        // code, never real protection.
        $seen = [];

        $claim = function (string $day) use (&$seen): void {
            if (!\in_array($day, self::DAY_CODES, true)) {
                throw new InvalidWeekStructureException(sprintf('Unknown day code "%s".', $day));
            }
            if (isset($seen[$day])) {
                throw new InvalidWeekStructureException(sprintf('Day "%s" belongs to more than one block/solo/excluded entry.', $day));
            }
            $seen[$day] = true;
        };

        foreach ($request->blocks as $block) {
            if (\count($block->days) < 2) {
                throw new InvalidWeekStructureException(sprintf('Block "%s" must contain at least 2 days.', $block->name));
            }
            if ('' === trim($block->name)) {
                throw new InvalidWeekStructureException('A block must have a non-empty name.');
            }
            foreach ($block->days as $day) {
                $claim($day);
            }
        }

        foreach ($request->solo as $day) {
            $claim($day);
        }

        foreach ($request->excluded as $day) {
            $claim($day);
        }

        $missing = array_diff(self::DAY_CODES, array_keys($seen));
        if ([] !== $missing) {
            throw new InvalidWeekStructureException(sprintf('Every day must be classified as a block, solo, or excluded day — missing: %s.', implode(', ', $missing)));
        }
    }

    private function resolveDefaultDutyType(PlanningTeam $team): DutyType
    {
        $existing = $this->dutyTypeRepository->findOneByTeamAndCode($team, self::DEFAULT_DUTY_TYPE_CODE);
        if (null !== $existing) {
            return $existing;
        }

        $dutyType = new DutyType($team, self::DEFAULT_DUTY_TYPE_CODE, 'Garde');
        $this->entityManager->persist($dutyType);

        return $dutyType;
    }

    /**
     * Get-or-create by a normalized code, scoped to the team — two entries
     * typed with the exact same label (after trimming/case-folding) always
     * share one AllocationFamily; an empty label means "no family",
     * WeekStructureService never invents one (docs/decisions.md D136).
     */
    private function resolveFamily(PlanningTeam $team, string $label): ?AllocationFamily
    {
        $label = trim($label);
        if ('' === $label) {
            return null;
        }

        $code = $this->familyCode($label);
        $existing = $this->familyRepository->findOneByTeamAndCode($team, $code);
        if (null !== $existing) {
            return $existing;
        }

        $family = new AllocationFamily($team, $code, $label);
        $this->entityManager->persist($family);

        return $family;
    }

    private function familyCode(string $label): string
    {
        $slug = strtoupper(trim($label));
        $slug = preg_replace('/[^A-Z0-9]+/', '_', $slug) ?? $slug;

        return trim($slug, '_');
    }

    /**
     * A code unique for this DutyPattern forever (docs/planning-domain.md
     * §9: `(team_id, code)` unique) — replace() only ever creates brand new
     * patterns, so a random suffix, never a human-chosen or reused label,
     * is all the uniqueness this needs (same convention as test fixtures
     * elsewhere in this codebase, e.g. `'GRP-'.bin2hex(random_bytes(3))`).
     */
    private function freshCode(string $prefix): string
    {
        return sprintf('%s-%s', $prefix, bin2hex(random_bytes(4)));
    }

    private function dayOffset(string $day): int
    {
        $offset = array_search($day, self::DAY_CODES, true);
        if (false === $offset) {
            throw new InvalidWeekStructureException(sprintf('Unknown day code "%s".', $day));
        }

        return $offset;
    }

    private static function dayName(string $day): string
    {
        $names = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $offset = array_search($day, self::DAY_CODES, true);

        return false !== $offset ? $names[$offset] : $day;
    }

    /**
     * @return list<string> day codes this pattern's components cover, in
     *                      DAY_CODES order (dayOffset 0=Monday..6=Sunday)
     */
    private function patternDayCodes(DutyPattern $pattern): array
    {
        $offsets = [];
        foreach ($pattern->getComponents() as $component) {
            $offsets[] = $component->getDayOffset();
        }
        sort($offsets);

        return array_map(static fn (int $offset): string => self::DAY_CODES[$offset], $offsets);
    }
}
