# Add operator-grade bulk intake telemetry and recovery controls

## Problem

Bulk intake visibility improved with per-set and total progress, but operator feedback is still noisy or incomplete during stress conditions:

- console shows transient request failures that are not always terminal
- no single concise batch summary during long runs
- no first-class "retry failed sets" action to recover without restarting all sets

## Outcomes

- operator can quickly distinguish transient churn from hard failure
- long runs provide concise periodic summary and clear terminal state
- partial failures are recoverable with one action

## Acceptance criteria

- UI includes:
  - total progress + ETA
  - per-set progress
  - terminal batch summary (`completed / failed / queued`)
- UI provides `Retry failed sets` action that only retries failed sets/files
- harness logs include minute-level concise status lines, not raw noise-only output
- transient `ERR_ABORTED` transport events are classified as debug unless they produce terminal failed rows

## Implementation notes

- add structured client-side event stream for uploader state transitions
- add summary panel with timestamps and counters
- add batch run id for cross-linking UI errors to server logs
- keep noise suppression limited to known transient classes; preserve real failures

## Test plan

- simulate transient chunk aborts and verify:
  - no false terminal failure
  - retry path succeeds without full restart
- verify summary output matches per-row state after completion
- verify harness artifacts capture terminal summary JSON

