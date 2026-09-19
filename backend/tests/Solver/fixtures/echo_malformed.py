#!/usr/bin/env python3
"""Test fixture only: simulates a subprocess that produces no valid JSON at
all (e.g. crashed before writing its result) — lets
OrToolsPlanningSolverTest verify the PHP adapter maps this to ERROR rather
than throwing an uncaught exception."""
print("not json at all")
