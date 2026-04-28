# Harden compute_orchestrator bootstrap failure handling and bad-host policy

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/compute_orchestrator/ARCHITECTURE.md; docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Architecture and Framesmith integration notes call out bad-host/bootstrap failure handling as core orchestration contract work, not optional robustness polish.

## Acceptance criteria

- [ ] Classify fatal bootstrap failures as `INFRA_FATAL` where appropriate.
- [ ] Stop polling immediately for fatal cases.
- [ ] Mark bad hosts with enough evidence to avoid repeated selection.
- [ ] Stop or destroy unusable fresh contracts safely.
- [ ] Retry another acquisition up to a bounded threshold.
- [ ] Add focused tests for bad-host classification and retry behaviour.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
