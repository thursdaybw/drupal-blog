# Define compute provider boundary beyond Vast.ai

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Next
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Framesmith notes call out that compute_orchestrator is currently very Vast.ai-shaped. Even if Drupal coupling is reduced, provider coupling may still block future portability.

## Grooming decision - 2026-04-28

Promote to `Groomed / Next` as a provider-boundary seam under card `42`.

Reason:

- Vast.ai is the current provider and can remain the current implementation.
- The architecture should still name the provider boundary so Vast-specific assumptions do not silently become the generic compute model.
- This likely becomes implementation work after more immediate state/ownership/semantics debt is clarified.

Related cards:

- Umbrella architecture review: `../done/2026-04-28-review-compute-orchestrator-architecture-and-drupal-coupling.md`
- Price/provider selection safety rail: `30-cap-generic-vllm-vast-offer-selection-by-max-hourly-price.md`

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
