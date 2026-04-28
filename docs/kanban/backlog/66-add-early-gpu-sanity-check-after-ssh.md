# Add early GPU sanity check after SSH

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/compute_orchestrator/ARCHITECTURE.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

compute_orchestrator architecture notes include a TODO to add an early GPU sanity check after SSH.

## Acceptance criteria

- [ ] Define minimum GPU sanity check command(s).
- [ ] Classify failures as retryable, provider issue, or infrastructure fatal.
- [ ] Surface failure reason in operator diagnostics.
- [ ] Add test coverage or fake executor coverage.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
