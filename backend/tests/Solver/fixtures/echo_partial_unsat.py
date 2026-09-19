#!/usr/bin/env python3
"""Test fixture only: STRICT -> UNSATISFIABLE, PARTIAL -> UNSATISFIABLE too
(docs/allocation-algorithm.md §10.5/§16 "existingDataConflict") — lets
OrToolsPlanningSolverTest verify the routing to
UnsatDiagnosticsBuilder::buildForExistingDataConflict() without depending
on a real scenario, which is unreachable through real CP-SAT today (no
fixedAssignments exist yet to create a genuine contradiction,
docs/decisions.md D090/D098)."""
import json
import sys

payload = json.loads(sys.stdin.read())

print(json.dumps({
    "status": "UNSATISFIABLE",
    "errorMessage": None,
    "assignments": [],
    "unassignedDutyUnitKeys": [],
    "phaseResults": [],
    "solveDurationMs": 1,
    "solverVersion": "fixture",
}))
