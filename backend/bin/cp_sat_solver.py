#!/usr/bin/env python3
"""CP-SAT STRICT GENERATE solver — invoked as a subprocess by
App\\Solver\\OrToolsPlanningSolver (docs/planning-solver.md). This script
is a pure mechanical translator: every coefficient, target, and dimension
weight it consumes was already computed by the PHP domain
(RequiredDemandBuilder, EffectiveExposureService, FairnessTargetService,
StructurallyForcedAnalyzer, App\\Solver\\CpSatPayloadBuilder). It never
reads membership timelines, participationFactor, or any fairness formula
of its own (docs/decisions.md D031: "le metier ne depend jamais
directement d'OR-Tools", and symmetrically: OR-Tools never depends
directly on the business formulas).

Reads one JSON object from stdin, writes one JSON object to stdout.
Exit code 0 on a well-formed result (including UNSATISFIABLE/UNKNOWN —
those are legitimate solver outcomes, not script failures). A non-zero
exit code / stderr output means the script itself could not run
(`status: "ERROR"` is the graceful counterpart, used when CP-SAT itself
reports MODEL_INVALID or another internal problem after parsing succeeded).

See docs/planning-solver.md for the full input/output JSON contract.
"""

import json
import sys
import time

from ortools.sat.python import cp_model

STATUS_MAP = {
    cp_model.OPTIMAL: "OPTIMAL",
    cp_model.FEASIBLE: "FEASIBLE",
    cp_model.INFEASIBLE: "UNSATISFIABLE",
    cp_model.UNKNOWN: "UNKNOWN",
    cp_model.MODEL_INVALID: "ERROR",
}


def map_status(cp_status):
    # Centralized mapping (docs/planning-solver.md §19) — anything CP-SAT
    # can return that is not in STATUS_MAP is treated as ERROR, never
    # silently coerced into UNKNOWN or UNSATISFIABLE.
    return STATUS_MAP.get(cp_status, "ERROR")


def build_model(payload):
    partial = payload.get("solveType") == "PARTIAL"

    model = cp_model.CpModel()
    variables = {}  # (dutyUnitKey, candidateId) -> BoolVar
    unassigned_vars = {}  # dutyUnitKey -> BoolVar, REQUIRED units only, PARTIAL only

    for unit in payload["dutyUnits"]:
        unit_key = unit["key"]
        unit_vars = []
        for candidate_id in unit["eligibleCandidates"]:
            # infeasible-by-construction (docs/planning-solver.md §4): a
            # variable is created ONLY for a pair the domain already
            # judged eligible. No variable is ever created for an
            # ineligible pair, not even fixed at 0.
            var = model.NewBoolVar(f"x[{unit_key}][{candidate_id}]")
            variables[(unit_key, candidate_id)] = var
            unit_vars.append(var)

        if unit["required"]:
            if partial:
                # docs/allocation-algorithm.md §10: Σx[d][c] + unassigned[d] = 1
                # — a REQUIRED duty is assigned exactly once or explicitly
                # left unassigned, never both, never silently dropped.
                unassigned = model.NewBoolVar(f"unassigned[{unit_key}]")
                unassigned_vars[unit_key] = unassigned
                model.Add(sum(unit_vars) + unassigned == 1)
            else:
                # Exactly one candidate (docs/planning-solver.md §6) — no
                # UNASSIGNED/slack/dummy candidate in a STRICT solve.
                model.AddExactlyOne(unit_vars)
        else:
            # Never forced, but a duty slot is still a single node — it
            # cannot be double-assigned even when covering it is optional
            # (docs/planning-solver.md §6). OPTIONAL units never get an
            # `unassigned` variable, in STRICT or PARTIAL — leaving one
            # uncovered is not a coverage default to track
            # (docs/planning-solver.md §Modèle PARTIAL).
            model.AddAtMostOne(unit_vars)

    for edge in payload.get("excludedEdges", []):
        key = (edge["dutyUnitKey"], edge["candidateId"])
        if key in variables:
            model.Add(variables[key] == 0)
        # A pair absent from `variables` was already ineligible — excluding
        # it again is a no-op, not an error (docs/planning-solver.md §22).

    # Global constraints (Lot 6D, docs/decisions.md D100): links two
    # otherwise-independent edges for the same candidate —
    # x[left,c] + x[right,c] <= 1. Computed entirely by
    # AssignmentConflictAnalyzer (PHP) from real Duty timestamps and the
    # snapshotted PlanningRuleSet; this script never recomputes overlap or
    # rest-gap arithmetic from raw timestamps itself.
    for conflict in payload.get("conflicts", []):
        left_key = (conflict["leftDutyUnitKey"], conflict["candidateId"])
        right_key = (conflict["rightDutyUnitKey"], conflict["candidateId"])
        if left_key in variables and right_key in variables:
            model.Add(variables[left_key] + variables[right_key] <= 1)
        # Either side absent from `variables` means that candidate was
        # never eligible for one of the two units in the first place —
        # AssignmentConflictAnalyzer only ever emits a conflict for
        # candidates eligible to *both*, so this should not happen; if it
        # ever does, there is simply nothing to constrain, not an error.

    return model, variables, unassigned_vars


def build_phase_expression(model, variables, term):
    # Sum of (existing variable * integer coefficient) + integer constant —
    # every coefficient was already scaled and rounded in PHP
    # (App\Solver\CpSatScale). This function performs no business
    # arithmetic of its own.
    parts = [term.get("constantOffset", 0)]
    for vc in term.get("variableCoefficients", []):
        key = (vc["dutyUnitKey"], vc["candidateId"])
        if key not in variables:
            raise ValueError(
                f"phase term references ({vc['dutyUnitKey']}, {vc['candidateId']}) "
                "which is not an eligible variable in this model — the PHP payload "
                "builder must never emit a coefficient for a non-existent pair."
            )
        parts.append(vc["coefficient"] * variables[key])
    return sum(parts)


def solve_phase(model, solver, variables, unassigned_vars, phase):
    kind = phase["kind"]
    terms = phase.get("terms", [])

    # MIN_UNASSIGNED_CRITICAL/MIN_UNASSIGNED_TOTAL (docs/allocation-algorithm.md
    # §10, PARTIAL only) operate directly on `unassigned[d]` slack
    # variables, never on `terms` (which only ever references real x[]
    # assignment variables) — a structurally different phase family, kept
    # out of the terms-is-empty neutrality check below on purpose: a
    # PARTIAL solve with zero REQUIRED duties would trivially have an
    # empty dutyUnitKeys list, and that IS still a real (trivial) phase to
    # solve/record, not a "no data backs this" neutral one.
    if kind in ("MIN_UNASSIGNED_CRITICAL", "MIN_UNASSIGNED_TOTAL"):
        duty_unit_keys = phase.get("dutyUnitKeys", [])
        objective_expr = sum(unassigned_vars[k] for k in duty_unit_keys if k in unassigned_vars)
        model.Minimize(objective_expr)
        status = solver.Solve(model)
        mapped = map_status(status)
        result = {
            "id": phase["id"],
            "attempted": True,
            "neutral": False,
            "optimal": mapped == "OPTIMAL",
            "objectiveValueScaled": int(round(solver.ObjectiveValue())) if mapped in ("OPTIMAL", "FEASIBLE") else None,
        }
        return result, (mapped, objective_expr)

    if not terms:
        # Neutral phase (docs/planning-solver.md §11/§12/§17/§18): no real
        # data backs this phase in this lot, or the dimension set it would
        # apply to is empty. Never solved, never fabricated — recorded as
        # trivially satisfied and the lexicographic chain continues
        # unconstrained by it.
        return {
            "id": phase["id"],
            "attempted": True,
            "neutral": True,
            "optimal": True,
            "objectiveValueScaled": 0,
        }, None

    expressions = [build_phase_expression(model, variables, t) for t in terms]
    direction = phase["direction"]

    if kind == "MAX_DEVIATION":
        worst = model.NewIntVar(-(10**15), 10**15, f"worst_{phase['id']}")
        for e in expressions:
            model.Add(worst >= e)
            model.Add(worst >= -e)
        objective_expr = worst
    elif kind == "SUM_DEVIATION":
        abs_vars = []
        for i, e in enumerate(expressions):
            a = model.NewIntVar(0, 10**15, f"abs_{phase['id']}_{i}")
            model.Add(a >= e)
            model.Add(a >= -e)
            abs_vars.append(a)
        objective_expr = sum(abs_vars)
    else:
        raise ValueError(f"unsupported phase kind for a non-neutral phase: {kind}")

    if direction == "MINIMIZE":
        model.Minimize(objective_expr)
    else:
        model.Maximize(objective_expr)

    status = solver.Solve(model)
    mapped = map_status(status)

    result = {
        "id": phase["id"],
        "attempted": True,
        "neutral": False,
        "optimal": mapped == "OPTIMAL",
        "objectiveValueScaled": int(round(solver.ObjectiveValue())) if mapped in ("OPTIMAL", "FEASIBLE") else None,
    }

    return result, (mapped, objective_expr)


def _is_timeout_status(mapped, max_time_in_seconds):
    # With max_time_in_seconds as the only configured stopping criterion
    # (no other limit is ever set anywhere in this script), CP-SAT only
    # ever returns something other than OPTIMAL/INFEASIBLE/MODEL_INVALID
    # because the deadline was hit before it could conclude — FEASIBLE
    # (a solution was found but optimality unproven) or UNKNOWN (not even
    # a solution found in time). This is a real, documented consequence of
    # CP-SAT's own stopping semantics, not a guess (docs/decisions.md D106).
    return max_time_in_seconds is not None and mapped in ("FEASIBLE", "UNKNOWN")


def run(payload):
    start = time.monotonic()
    model, variables, unassigned_vars = build_model(payload)
    solver = cp_model.CpSolver()
    solver.parameters.num_search_workers = payload.get("numWorkers", 1)
    solver.parameters.random_seed = payload.get("randomSeed", 0)
    max_time_in_seconds = payload.get("maxTimeInSeconds")
    if max_time_in_seconds is not None:
        solver.parameters.max_time_in_seconds = max_time_in_seconds
    timeout_hit = False

    phase_results = []

    if payload.get("feasibilityOnly", False):
        status = solver.Solve(model)
        mapped = map_status(status)
        timeout_hit = _is_timeout_status(mapped, max_time_in_seconds)
        duration_ms = int((time.monotonic() - start) * 1000)
        return {
            "status": mapped,
            "errorMessage": None,
            "assignments": [],
            "unassignedDutyUnitKeys": [],
            "phaseResults": [],
            "solveDurationMs": duration_ms,
            "solverVersion": "",
            "timeoutHit": timeout_hit,
        }

    # Step 0: base feasibility (HARD eligibility + coverage), no objective —
    # guarantees at least one real Solve() call happens even if every
    # phase below turns out neutral (docs/planning-solver.md §15).
    base_status = solver.Solve(model)
    base_mapped = map_status(base_status)
    timeout_hit = timeout_hit or _is_timeout_status(base_mapped, max_time_in_seconds)

    if base_mapped not in ("OPTIMAL", "FEASIBLE"):
        duration_ms = int((time.monotonic() - start) * 1000)
        for phase in payload["phases"]:
            phase_results.append({
                "id": phase["id"],
                "attempted": False,
                "neutral": False,
                "optimal": None,
                "objectiveValueScaled": None,
            })
        return {
            "status": base_mapped,
            "errorMessage": None,
            "assignments": [],
            "unassignedDutyUnitKeys": [],
            "phaseResults": phase_results,
            "solveDurationMs": duration_ms,
            "solverVersion": "",
            "timeoutHit": timeout_hit,
        }

    overall_status = base_mapped
    chain_stopped = False

    for phase in payload["phases"]:
        if chain_stopped:
            phase_results.append({
                "id": phase["id"],
                "attempted": False,
                "neutral": False,
                "optimal": None,
                "objectiveValueScaled": None,
            })
            continue

        result, solved = solve_phase(model, solver, variables, unassigned_vars, phase)
        phase_results.append(result)

        if solved is None:
            # neutral — nothing to lock, continue with the next phase.
            continue

        mapped, objective_expr = solved
        timeout_hit = timeout_hit or _is_timeout_status(mapped, max_time_in_seconds)

        if mapped == "OPTIMAL":
            overall_status = "OPTIMAL"
            bound = int(round(solver.ObjectiveValue()))
            if phase["direction"] == "MINIMIZE":
                model.Add(objective_expr <= bound)
            else:
                model.Add(objective_expr >= bound)
            continue

        if mapped == "FEASIBLE":
            # Conservative policy (docs/decisions.md D089): a non-final
            # phase proven only FEASIBLE (optimality not proven) must not
            # be silently treated as OPTIMAL and used to constrain later
            # phases — the lexicographic chain stops here.
            overall_status = "FEASIBLE"
            chain_stopped = True
            continue

        # UNSATISFIABLE / UNKNOWN / ERROR — stop the chain entirely.
        overall_status = mapped
        chain_stopped = True

    assignments = []
    unassigned_duty_unit_keys = []
    if overall_status in ("OPTIMAL", "FEASIBLE"):
        for (unit_key, candidate_id), var in variables.items():
            if solver.Value(var) == 1:
                assignments.append({"dutyUnitKey": unit_key, "candidateId": candidate_id})
        for unit_key, var in unassigned_vars.items():
            if solver.Value(var) == 1:
                unassigned_duty_unit_keys.append(unit_key)

    duration_ms = int((time.monotonic() - start) * 1000)

    return {
        "status": overall_status,
        "errorMessage": None,
        "assignments": assignments,
        "unassignedDutyUnitKeys": unassigned_duty_unit_keys,
        "phaseResults": phase_results,
        "solveDurationMs": duration_ms,
        "solverVersion": "",
        "timeoutHit": timeout_hit,
    }


def main():
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        print(json.dumps({
            "status": "ERROR",
            "errorMessage": f"invalid JSON payload: {exc}",
            "assignments": [],
            "phaseResults": [],
            "solveDurationMs": 0,
            "solverVersion": "",
            "timeoutHit": False,
        }))
        sys.exit(0)

    try:
        import ortools
        result = run(payload)
        result["solverVersion"] = getattr(ortools, "__version__", "")
        print(json.dumps(result))
    except Exception as exc:  # noqa: BLE001 — deliberately broad: any failure here must become status ERROR, never a bare traceback the PHP side has to parse.
        print(json.dumps({
            "status": "ERROR",
            "errorMessage": f"{type(exc).__name__}: {exc}",
            "assignments": [],
            "phaseResults": [],
            "solveDurationMs": 0,
            "solverVersion": "",
            "timeoutHit": False,
        }))


if __name__ == "__main__":
    main()
