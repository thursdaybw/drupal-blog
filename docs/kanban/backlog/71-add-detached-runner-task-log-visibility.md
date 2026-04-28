# Add detached-runner task-log visibility

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

Framesmith notes call out detached-runner observability: debugging proved the runner works synchronously, but detached execution needs task-scoped output capture.

## Acceptance criteria

- [ ] Define where task-scoped runner output lives.
- [ ] Capture stdout/stderr/status for detached runs without scraping noisy tool logs.
- [ ] Expose debug/admin retrieval path first.
- [ ] Decide later UI viewport separately.
- [ ] Keep the concept portable beyond Drupal-specific batch/shell conventions.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
