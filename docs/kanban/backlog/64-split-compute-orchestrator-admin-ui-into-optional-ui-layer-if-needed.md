# Split compute_orchestrator admin UI into optional UI layer if needed

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Candidate
Grooming priority: Later
Source: html/modules/custom/compute_orchestrator/README.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

The compute_orchestrator README says admin UI routes, forms, and Drupal Batch wrappers currently live in the module for convenience, but a future refactor may move the UI into an optional submodule.

## Grooming decision - 2026-04-28

Keep as `Candidate / Later`, not `Now` implementation work.

Reason:

- The UI split may be the correct long-term shape, but doing it immediately could create churn before the architecture review names the core/adapters boundary.
- Treat this as a possible outcome of card `42`, not as a pre-decided refactor.
- The near-term requirement is to keep core orchestration services understandable and separable; an actual optional UI submodule can wait until there is evidence it buys freedom rather than ceremony.

Related card:

- Umbrella architecture review: `42-review-compute-orchestrator-architecture-and-drupal-coupling.md`

## Acceptance criteria

- [ ] Decide whether headless deployments are near enough to justify the split.
- [ ] Inventory admin UI/forms/Batch code that would move.
- [ ] Define core orchestration services that must remain usable without UI.
- [ ] If accepted, create implementation cards for the split.
- [ ] If deferred, link this to the broader architecture/coupling review.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
