#!/usr/bin/env python3
"""Test fixture only: simulates cp_sat_solver.py returning UNKNOWN, without
running any real CP-SAT solve — lets OrToolsPlanningSolverTest verify the
PHP-side status mapping never coerces UNKNOWN into UNSATISFIABLE, without
needing to actually force CP-SAT into an inconclusive state."""
import json

print(json.dumps({
    "status": "UNKNOWN",
    "errorMessage": None,
    "assignments": [],
    "phaseResults": [],
    "solveDurationMs": 1,
    "solverVersion": "fixture",
}))
