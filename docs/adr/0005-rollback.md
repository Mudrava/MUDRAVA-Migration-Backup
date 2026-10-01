# ADR-0005: Rollback - honest restore points, no false guarantees

Status: accepted (2026-09-25)

## Decision
Before destructive restore, preflight computes rollback headroom (free disk vs
current site size + archive size). Safe mode creates a restore point only when
storage physically permits preserving the previous state. We never advertise
rollback as guaranteed when it is not. A low-disk destructive mode exists only
under Advanced with explicit high-risk confirmation and is tested with
process-kill at every stage.

## Consequences
- UI copy states required free space ("Safe rollback requires ~280 GB more").
- Two restore modes with different test suites; both must never produce
  silent partial success.
