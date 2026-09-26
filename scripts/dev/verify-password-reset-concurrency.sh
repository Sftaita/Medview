#!/usr/bin/env bash
# Manual/CI-adjacent verification that PasswordResetService::confirmReset()'s
# row lock (SELECT ... FOR UPDATE, see docs/authentication.md §16.4) actually
# serializes a real race between two independent database connections — not
# just two sequential calls on one connection, which is all a PHPUnit run can
# exercise under dama/doctrine-test-bundle (one test = one transaction = one
# connection, see docs/decisions.md D141).
#
# Fires two genuinely concurrent POST /api/password-reset/confirm requests
# against a REAL running stack (backend + Postgres + Mailpit), with the SAME
# raw token and two different new passwords, and asserts that exactly one
# succeeds. Requires `docker compose up -d` already running locally.
#
# Not part of the PHPUnit suite: it needs a live stack and real wall-clock
# concurrency, which a unit/integration test environment can't guarantee.
# Timing is best-effort (two backgrounded curl processes started back to
# back) — reliable in practice because the loser blocks on Postgres's row
# lock for the whole duration of the winner's transaction, not because this
# script guarantees byte-for-byte simultaneous arrival.
#
# Exit 0 = exactly one of the two requests succeeded (the invariant holds).
# Exit 1 = anything else (both succeeded, both failed, unexpected status).
set -euo pipefail

BACKEND_URL="${BACKEND_URL:-http://localhost:8010}"
MAILPIT_URL="${MAILPIT_URL:-http://localhost:8026}"
SCRATCH_DIR="${SCRATCH_DIR:-$(mktemp -d)}"
EMAIL="concurrency-check.$(date +%s)@example.com"

fail() { echo "FAIL: $*" >&2; exit 1; }

echo "Registering a throwaway test account: $EMAIL"
register_status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BACKEND_URL/api/register" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"plainPassword\":\"OriginalPassword1\",\"firstName\":\"Concurrency\",\"lastName\":\"Check\",\"phone\":\"+32 470 00 00 00\"}")
[[ "$register_status" == "201" ]] || fail "registration failed (HTTP $register_status)"

echo "Requesting a password reset"
request_status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BACKEND_URL/api/password-reset/request" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\"}")
[[ "$request_status" == "200" ]] || fail "reset request failed (HTTP $request_status)"

echo "Reading the raw token back from Mailpit"
msg_id=$(curl -s "$MAILPIT_URL/api/v1/messages?limit=1" | grep -oE '"ID":"[^"]+"' | head -1 | cut -d'"' -f4)
[[ -n "$msg_id" ]] || fail "no message found in Mailpit — is $MAILPIT_URL reachable?"
curl -s "$MAILPIT_URL/api/v1/message/$msg_id" -o "$SCRATCH_DIR/msg.json"
token=$(grep -oE 'reset-password#token=[0-9a-f]{64}' "$SCRATCH_DIR/msg.json" | head -1 | sed 's/.*token=//')
[[ -n "$token" ]] || fail "could not extract a token from the reset email"

echo "Firing two concurrent confirm requests with the same token"
(curl -s -w '\nHTTP:%{http_code}\n' -X POST "$BACKEND_URL/api/password-reset/confirm" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$token\",\"newPassword\":\"RaceWinnerA1\"}" > "$SCRATCH_DIR/race_a.txt" 2>&1) &
pid_a=$!
(curl -s -w '\nHTTP:%{http_code}\n' -X POST "$BACKEND_URL/api/password-reset/confirm" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$token\",\"newPassword\":\"RaceWinnerB1\"}" > "$SCRATCH_DIR/race_b.txt" 2>&1) &
pid_b=$!
wait "$pid_a" "$pid_b"

status_a=$(grep -oE 'HTTP:[0-9]+' "$SCRATCH_DIR/race_a.txt" | cut -d: -f2)
status_b=$(grep -oE 'HTTP:[0-9]+' "$SCRATCH_DIR/race_b.txt" | cut -d: -f2)
echo "Request A: HTTP $status_a"
echo "Request B: HTTP $status_b"

successes=0
[[ "$status_a" == "200" ]] && successes=$((successes + 1))
[[ "$status_b" == "200" ]] && successes=$((successes + 1))

[[ "$successes" -eq 1 ]] || fail "expected exactly one success, got $successes (both requests: $status_a / $status_b)"

echo "PASS: exactly one of the two concurrent confirmations succeeded — the row lock holds under real concurrency."
