# ADR-0004: Resumability - durable state machine of short idempotent jobs

Status: accepted (2026-09-25)

## Context
`max_execution_time` cannot be reliably disabled on shared hosting; browser
connections drop; PHP workers die. `set_time_limit(0)` is not a strategy.

## Decision
Every long operation is a durable state machine
(Preflight→Inventory→Database→Files→Manifest→Completed, with Paused/Failed/
Recovering/RolBack). Jobs are short-running, checkpointed, retryable,
idempotent where possible, observable, bounded in memory, safe against
duplicate requests. Runner: WP-Cron plus browser-driven REST ticks (works when
cron is absent). Job lock with TTL prevents concurrent execution.
Checkpoints persist sequence + cursor; resume replays from last valid
checkpoint; inter-checkpoint work is idempotent (overwrite/replace semantics).

## Consequences
- Progress survives timeout, browser close, worker kill, network reset.
- Tests must kill real containers mid-migration, not mock success.
