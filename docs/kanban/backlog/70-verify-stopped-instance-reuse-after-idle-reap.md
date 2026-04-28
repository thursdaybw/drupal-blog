# Verify stopped-instance reuse after idle reap

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Framesmith integration notes identified a potential reaped-instance reuse bug: an available pooled instance stopped by idle reap may not be restarted and reused on later acquire.

## Acceptance criteria

- [ ] Reproduce or disprove stopped-instance reuse issue.
- [ ] Ensure acquire prefers available running instances, then available stopped instances that can be restarted, then fresh fallback.
- [ ] Add focused tests for stopped-instance reuse after reap.
- [ ] Update pool admin help text to explain lease-state/runtime-state split.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
