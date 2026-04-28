# Define compute provider boundary beyond Vast.ai

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

Framesmith notes call out that compute_orchestrator is currently very Vast.ai-shaped. Even if Drupal coupling is reduced, provider coupling may still block future portability.

## Acceptance criteria

- [ ] Inventory Vast.ai-specific assumptions in lifecycle/search/provision/state logic.
- [ ] Define provider contracts and translation boundaries.
- [ ] Identify which services should become provider adapters.
- [ ] Decide whether local/self-hosted/multi-provider support is a real near-term goal or later option.
- [ ] Create implementation follow-ups for approved provider decoupling work.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
