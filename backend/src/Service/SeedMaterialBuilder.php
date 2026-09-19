<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningSnapshot;

/**
 * `seedMaterial` (docs/allocation-algorithm.md §13, docs/decisions.md
 * D106) — recorded on every solved `PlanningGeneration` for audit/
 * reproducibility identity. **Not** consumed by the solve itself in this
 * lot: `ObjectivePhase::deterministicTieBreak()` (phase 8) stays neutral
 * (D088 — the phase is a structural identity with no real parameter yet),
 * and CP-SAT's own `random_seed` is a separate, purely technical search
 * knob (`docs/planning-solver.md` §20 — "distinct du futur tie-break
 * métier D088"), unaffected by this value. Computing and persisting this
 * now, ahead of a real consumer, is legitimate specifically because
 * `docs/allocation-algorithm.md` §14 lists it as part of "l'identité
 * reproductible d'une génération" — an audit record, not a solver input —
 * distinct from inventing a business threshold with no real backing data.
 *
 *   seedMaterial = teamStableKey + planningPeriodStableKey + rulesVersion
 *                + snapshotHash + algorithmVersion + solverParameterSetVersion
 *                + explicitSeed
 *
 * `explicitSeed` always takes its documented default derivation from
 * `(teamStableKey, planningPeriodStableKey)` — no override mechanism
 * exists (that is a SIMULATE-only concept, §13, not implemented).
 */
final class SeedMaterialBuilder
{
    public function build(
        PlanningSnapshot $snapshot,
        int $rulesVersion,
        string $snapshotHash,
        string $algorithmVersion,
        int $solverParameterSetVersion,
    ): string {
        $planningPeriod = $snapshot->getGeneration()->getPlanningPeriod();
        $teamStableKey = (string) $planningPeriod->getTeam()->getStableId();
        $planningPeriodStableKey = (string) $planningPeriod->getStableId();

        $explicitSeed = hash('sha256', $teamStableKey.'|'.$planningPeriodStableKey);

        $material = implode('|', [
            $teamStableKey,
            $planningPeriodStableKey,
            (string) $rulesVersion,
            $snapshotHash,
            $algorithmVersion,
            (string) $solverParameterSetVersion,
            $explicitSeed,
        ]);

        return hash('sha256', $material);
    }
}
