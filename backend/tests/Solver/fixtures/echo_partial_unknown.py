#!/usr/bin/env python3
"""Test fixture only: STRICT -> UNSATISFIABLE, PARTIAL -> UNKNOWN. Lets
OrToolsPlanningSolverTest verify that an inconclusive PARTIAL result is
never coerced into a proven INCOMPLETE/UNSATISFIABLE outcome
(docs/decisions.md D094)."""
import json
import sys

payload = json.loads(sys.stdin.read())

if payload.get("solveType") == "PARTIAL":
    result = {
        "status": "UNKNOWN",
        "errorMessage": None,
        "assignments": [],
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
