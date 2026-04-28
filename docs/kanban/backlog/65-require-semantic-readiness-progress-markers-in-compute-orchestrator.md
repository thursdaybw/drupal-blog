# Require semantic readiness progress markers in compute_orchestrator

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

Architecture notes say readiness progress detection may be too eager: log/process/GPU diffs can count as progress even when nothing semantically changes.

## Acceptance criteria

- [ ] Inventory current progress detection signals.
- [ ] Define semantic progress markers, such as HTTP port bind or `/v1/models` success.
- [ ] Adjust readiness loops to avoid endless warm-up on meaningless churn.
- [ ] Add tests for semantic progress versus noise.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
