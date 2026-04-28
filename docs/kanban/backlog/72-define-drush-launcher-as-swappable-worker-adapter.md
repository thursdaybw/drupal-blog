# Define Drush launcher as swappable worker adapter

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

Framesmith notes explicitly say Drush is acceptable as a temporary worker entrypoint while compute_orchestrator lives in Drupal, but Drush must not become the conceptual async job contract.

## Acceptance criteria

- [ ] Define launcher/worker interface in framework-light terms.
- [ ] Treat Drush command execution as one adapter implementation.
- [ ] Identify alternative future implementations:
  - Symfony console;
  - process supervisor;
  - queue worker;
  - standalone service runner.
- [ ] Avoid deepening coupling to Drush-specific behaviour in future fixes.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
