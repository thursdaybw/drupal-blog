# Review compute task CRUD and storage ownership boundary

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Now
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Framesmith notes identify task CRUD/storage ownership as unresolved: Framesmith has a task store inside compute_orchestrator, while video_forge has entity-backed task concepts elsewhere.

## Grooming decision - 2026-04-28

Promote to `Groomed / Now` as a concrete child workstream under card `42`.

Reason:

- Task ownership is a high-leverage architecture seam.
- Framesmith task state currently lives inside `compute_orchestrator`; that may be fine temporarily, but the ownership boundary must be named before extraction pressure grows.
- This should be reviewed together with durable Framesmith task persistence, but not merged away because generic compute job state and product-specific Framesmith task state may diverge.

Related cards:

- Umbrella architecture review: `42-review-compute-orchestrator-architecture-and-drupal-coupling.md`
- Durable Framesmith persistence decision: `41-decide-durable-framesmith-task-persistence-model.md`

## Acceptance criteria

- [ ] Decide whether task records are Framesmith-owned, generic compute-job-owned, or host-app-owned.
- [ ] Define the persistence boundary and adapter interface.
- [ ] Align with durable Framesmith task persistence card if needed.
- [ ] Avoid hard-wiring to Drupal state or a single module-owned storage mechanism.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
