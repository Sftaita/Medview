#!/usr/bin/env python3
"""Test fixture only: STRICT -> UNSATISFIABLE, PARTIAL -> OPTIMAL with zero
unassigned duties. Lets OrToolsPlanningSolverTest verify the rare-but-must-
stay-representable "STRICT UNSAT, PARTIAL still finds complete coverage"
case (docs/allocation-algorithm.md §7) without depending on a real
scenario — unreachable through real CP-SAT with today's constraint set
(no cross-duty coupling exists yet, see docs/decisions.md)."""
import json
import sys

payload = json.loads(sys.stdin.read())

if payload.get("solveType") == "PARTIAL":
    result = {
        "status": "OPTIMAL",
        "errorMessage": None,
        "assignments": [{"dutyUnitKey": "u1", "candidateId": "c1"}],
        "unassignedDutyUnitKeys": [],
        "phaseResults": [],
        "solveDurationMs": 1,
        "solverVersion": "fixture",
    }
else:
    result = {
        "status": "UNSATISFIABLE",
        "errorMessage": None,
        "assignments": [],
        "unassignedDutyUnitKeys": [],
        "phaseResults": [],
        "solveDurationMs": 1,
        "solverVersion": "fixture",
    }

print(json.dumps(result))
