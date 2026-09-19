#!/usr/bin/env python3
"""Test fixture only: simulates a STRICT solve returning FEASIBLE (a
solution found, optimality not proven) — CP-SAT converges to OPTIMAL too
quickly on small real test problems to reliably force this state for
real, so this fixture lets OrToolsPlanningSolverTest verify the
FEASIBLE -> COMPLETE mapping in isolation."""
import json

print(json.dumps({
    "status": "FEASIBLE",
    "errorMessage": None,
    "assignments": [{"dutyUnitKey": "u1", "candidateId": "c1"}],
    "unassignedDutyUnitKeys": [],
    "phaseResults": [],
    "solveDurationMs": 1,
    "solverVersion": "fixture",
}))
